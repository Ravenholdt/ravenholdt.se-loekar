<?php

function voteStoragePath(): string
{
	return __DIR__ . '/votes.json';
}

function voteLockPath(string $voteFile): string
{
	$storageRoot = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
	$lockDirectory = $storageRoot . '/sjyssta-lokar-' . hash('sha256', dirname($voteFile));
	if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0700, true) && !is_dir($lockDirectory)) {
		throw new RuntimeException('Could not create the vote lock directory.');
	}

	return $lockDirectory . '/votes.lock';
}

function loadVoteCounts(string $voteFile, ?string $vote = null): array
{
	if (!is_file($voteFile)) {
		throw new RuntimeException('Vote file is missing. Create votes.json in the web directory and grant PHP write access.');
	}

	$lockPath = voteLockPath($voteFile);
	$lock = @fopen($lockPath, 'c');
	if ($lock === false) {
		throw new RuntimeException('Could not open the vote storage lock.');
	}

	if (!@flock($lock, LOCK_EX)) {
		fclose($lock);
		throw new RuntimeException('Could not lock the vote storage.');
	}

	try {
		$contents = @file_get_contents($voteFile);
		if ($contents === false) {
			throw new RuntimeException('Could not read the vote storage.');
		}
		if ($contents === '') {
			throw new RuntimeException('Vote storage is empty.');
		}

		$counts = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($counts)) {
			throw new RuntimeException('Vote storage has an invalid format.');
		}

		foreach ($counts as $filename => $count) {
			if (!is_string($filename) || !is_int($count) || $count < 0) {
				throw new RuntimeException('Vote storage contains invalid vote counts.');
			}
		}

		if ($vote !== null) {
			if (($counts[$vote] ?? 0) === PHP_INT_MAX) {
				throw new RuntimeException('Vote count limit reached.');
			}
			$counts[$vote] = ($counts[$vote] ?? 0) + 1;
			saveVoteCounts($voteFile, $counts);
		}

		return $counts;
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

function saveVoteCounts(string $voteFile, array $counts): void
{
	$directory = dirname($voteFile);
	$tempFile = @tempnam($directory, '.votes-');
	if ($tempFile === false) {
		throw new RuntimeException('Could not create a temporary vote file. Grant PHP write access to the web directory.');
	}

	$handle = null;
	try {
		if (realpath(dirname($tempFile)) !== realpath($directory)) {
			throw new RuntimeException('Could not create a temporary vote file in the web directory. Grant PHP write access to the web directory.');
		}

		if (!@chmod($tempFile, 0600)) {
			throw new RuntimeException('Could not secure the temporary vote file.');
		}

		$json = json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
		$handle = @fopen($tempFile, 'wb');
		if ($handle === false) {
			throw new RuntimeException('Could not open the temporary vote file.');
		}

		$offset = 0;
		$jsonLength = strlen($json);
		while ($offset < $jsonLength) {
			$written = fwrite($handle, substr($json, $offset));
			if ($written === false || $written === 0) {
				throw new RuntimeException('Could not write the temporary vote file.');
			}
			$offset += $written;
		}

		if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
			throw new RuntimeException('Could not flush vote counts to disk.');
		}
		fclose($handle);
		$handle = null;

		if (!@rename($tempFile, $voteFile)) {
			throw new RuntimeException('Could not atomically replace votes.json. Grant PHP write access to the web directory.');
		}
		$tempFile = null;
	} finally {
		if (is_resource($handle)) {
			fclose($handle);
		}
		if ($tempFile !== null && is_file($tempFile)) {
			@unlink($tempFile);
		}
	}
}

function getImageFiles(string $imageDirectory): array
{
	$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
	if (!is_dir($imageDirectory)) {
		return [];
	}

	$entries = @scandir($imageDirectory);
	if ($entries === false) {
		throw new RuntimeException('Could not read the images directory.');
	}

	$images = [];
	foreach ($entries as $entry) {
		$extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
		$path = $imageDirectory . DIRECTORY_SEPARATOR . $entry;
		if (in_array($extension, $allowedExtensions, true) && is_file($path) && !is_link($path)) {
			$images[] = $entry;
		}
	}

	sort($images, SORT_NATURAL | SORT_FLAG_CASE);
	return $images;
}

function escapeHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function imageUrl(string $filename): string
{
	return 'images/' . rawurlencode($filename);
}

function thumbnailUrl(string $filename): string
{
	return 'thumbnail.php?image=' . rawurlencode($filename);
}

function totalVotesForImages(array $voteCounts, array $images): int
{
	return array_sum(array_intersect_key($voteCounts, array_flip($images)));
}

function voteRateLimitRemaining(?float $lastVoteAt, float $now): int
{
	return $lastVoteAt === null ? 0 : max(0, (int) ceil(2 - ($now - $lastVoteAt)));
}
