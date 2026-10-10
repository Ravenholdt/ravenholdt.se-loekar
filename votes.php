<?php

function voteStorageDirectory(): string
{
	$storageRoot = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
	return $storageRoot . '/sjyssta-lokar-' . hash('sha256', __DIR__);
}

function loadVoteCounts(string $storageDirectory, ?string $vote = null): array
{
	if (!is_dir($storageDirectory) && !@mkdir($storageDirectory, 0700, true) && !is_dir($storageDirectory)) {
		throw new RuntimeException('Could not create the vote storage directory.');
	}

	$lockPath = $storageDirectory . '/votes.lock';
	$lock = @fopen($lockPath, 'c');
	if ($lock === false) {
		throw new RuntimeException('Could not open the vote storage lock.');
	}

	if (!@flock($lock, LOCK_EX)) {
		fclose($lock);
		throw new RuntimeException('Could not lock the vote storage.');
	}

	try {
		$voteFile = $storageDirectory . '/votes.json';
		$voteFileExists = is_file($voteFile);
		$contents = $voteFileExists ? @file_get_contents($voteFile) : '';
		if ($contents === false) {
			throw new RuntimeException('Could not read the vote storage.');
		}
		if ($voteFileExists && $contents === '') {
			throw new RuntimeException('Vote storage is empty.');
		}

		$counts = $voteFileExists ? json_decode($contents, true, 512, JSON_THROW_ON_ERROR) : [];
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
			$tempFile = @tempnam($storageDirectory, '.votes-');
			if ($tempFile === false) {
				throw new RuntimeException('Could not create a temporary vote file.');
			}

			try {
				$json = json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
				if (@file_put_contents($tempFile, $json) !== strlen($json)) {
					throw new RuntimeException('Could not write vote counts.');
				}
				if (!@chmod($tempFile, 0600) || !@rename($tempFile, $voteFile)) {
					throw new RuntimeException('Could not save vote counts.');
				}
				$tempFile = null;
			} finally {
				if ($tempFile !== null && is_file($tempFile)) {
					@unlink($tempFile);
				}
			}
		}

		return $counts;
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}
