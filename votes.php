<?php

function voteStoragePath(): string
{
	return __DIR__ . '/votes.json';
}

function voteLockPath(): string
{
	$storageRoot = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
	$lockDirectory = $storageRoot . '/sjyssta-lokar-' . hash('sha256', __DIR__);
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

	$lockPath = voteLockPath();
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
			$json = json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
			$voteHandle = @fopen($voteFile, 'c+');
			if ($voteHandle === false) {
				throw new RuntimeException('Could not open votes.json for writing. Grant PHP write access to the file.');
			}

			try {
				if (!ftruncate($voteHandle, 0) || rewind($voteHandle) === false || fwrite($voteHandle, $json) !== strlen($json) || !fflush($voteHandle)) {
					throw new RuntimeException('Could not save vote counts to votes.json.');
				}
			} finally {
				fclose($voteHandle);
			}
		}

		return $counts;
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}
