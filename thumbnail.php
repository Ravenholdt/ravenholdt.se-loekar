<?php
require_once __DIR__ . '/votes.php';

$filename = $_GET['image'] ?? null;
$images = getImageFiles(__DIR__ . '/images');
if (!is_string($filename) || !in_array($filename, $images, true)) {
	http_response_code(404);
	exit('Image not found.');
}

$imagePath = __DIR__ . '/images/' . $filename;
$realImagePath = realpath($imagePath);
$realImageDirectory = realpath(__DIR__ . '/images');
if ($realImagePath === false || $realImageDirectory === false || !str_starts_with($realImagePath, $realImageDirectory . DIRECTORY_SEPARATOR) || !is_readable($realImagePath)) {
	http_response_code(404);
	exit('Image not found.');
}

$storageRoot = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
$cacheDirectory = $storageRoot . '/sjyssta-lokar-thumbnails-' . hash('sha256', __DIR__);
$sourceVersion = $realImagePath . ':' . filemtime($realImagePath) . ':' . filesize($realImagePath);
$thumbnailPath = $cacheDirectory . '/' . hash('sha256', $sourceVersion) . '.jpg';

if (!is_file($thumbnailPath)) {
	if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0700, true) && !is_dir($cacheDirectory)) {
		error_log('Thumbnail error: Could not create cache directory ' . $cacheDirectory);
	} elseif (is_executable('/usr/bin/convert')) {
		$tempFile = @tempnam($cacheDirectory, '.thumbnail-');
		if ($tempFile === false) {
			error_log('Thumbnail error: Could not create a temporary file in ' . $cacheDirectory);
		} else {
			$process = function_exists('proc_open')
				? proc_open(
					[
						'/usr/bin/convert',
						$realImagePath,
						'-auto-orient',
						'-thumbnail',
						'192x132^',
						'-gravity',
						'center',
						'-extent',
						'192x132',
						'-strip',
						'-quality',
						'78',
						'jpeg:' . $tempFile,
					],
					[
						0 => ['pipe', 'r'],
						1 => ['file', '/dev/null', 'w'],
						2 => ['file', '/dev/null', 'w'],
					],
					$pipes
				)
				: false;

			if (is_resource($process)) {
				fclose($pipes[0]);
				$exitCode = proc_close($process);
				if ($exitCode === 0 && is_file($tempFile) && filesize($tempFile) > 0 && @rename($tempFile, $thumbnailPath)) {
					$tempFile = null;
				} else {
					error_log('Thumbnail error: ImageMagick could not create a thumbnail for ' . $filename);
				}
			} else {
				error_log('Thumbnail error: Could not start ImageMagick for ' . $filename);
			}

			if ($tempFile !== null && is_file($tempFile)) {
				@unlink($tempFile);
			}
		}
	} else {
		error_log('Thumbnail error: /usr/bin/convert is not available; serving original image for ' . $filename);
	}
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');
if (is_file($thumbnailPath) && is_readable($thumbnailPath)) {
	header('Content-Type: image/jpeg');
	header('Content-Length: ' . filesize($thumbnailPath));
	readfile($thumbnailPath);
	exit;
}

$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($realImagePath);
header('Content-Type: ' . (is_string($mimeType) && str_starts_with($mimeType, 'image/') ? $mimeType : 'application/octet-stream'));
header('Content-Length: ' . filesize($realImagePath));
readfile($realImagePath);
