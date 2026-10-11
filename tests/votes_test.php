<?php
require_once __DIR__ . '/../votes.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
	if ($expected !== $actual) {
		throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
	}
}

function assertThrows(callable $operation, string $message): void
{
	try {
		$operation();
	} catch (JsonException | RuntimeException) {
		return;
	}

	throw new RuntimeException($message . ': expected an exception.');
}

$testDirectory = sys_get_temp_dir() . '/sjyssta-lokar-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testDirectory, 0700)) {
	throw new RuntimeException('Could not create the test directory.');
}

$voteFile = $testDirectory . '/votes.json';
file_put_contents($voteFile, json_encode(['onion.jpg' => 2, 'removed.jpg' => 9], JSON_THROW_ON_ERROR));
$lockPath = voteLockPath($voteFile);

try {
	$counts = loadVoteCounts($voteFile);
	assertSameValue(11, array_sum($counts), 'Vote data loads');

	$counts = loadVoteCounts($voteFile, 'onion.jpg');
	assertSameValue(3, $counts['onion.jpg'], 'Votes increment');
	assertSameValue(3, totalVotesForImages($counts, ['onion.jpg']), 'Totals include only visible images');
	assertSameValue([], glob($testDirectory . '/.votes-*'), 'Atomic save removes temporary files');

	$invalidVoteFile = $testDirectory . '/invalid.json';
	file_put_contents($invalidVoteFile, '{"onion.jpg":-1}');
	assertThrows(static fn() => loadVoteCounts($invalidVoteFile), 'Invalid counts are rejected');

	$emptyVoteFile = $testDirectory . '/empty.json';
	file_put_contents($emptyVoteFile, '');
	assertThrows(static fn() => loadVoteCounts($emptyVoteFile), 'Empty vote files are rejected');

	assertSameValue(1, voteRateLimitRemaining(100.0, 101.1), 'Rate limit remains during the final second');
	assertSameValue(0, voteRateLimitRemaining(100.0, 102.0), 'Rate limit expires after two seconds');
	assertSameValue(0, voteRateLimitRemaining(null, 100.0), 'First vote is not rate limited');

	$blockedDestination = $testDirectory . '/blocked.json';
	mkdir($blockedDestination);
	assertThrows(static fn() => saveVoteCounts($blockedDestination, ['onion.jpg' => 1]), 'Failed atomic replacement is reported');
	assertSameValue([], glob($testDirectory . '/.votes-*'), 'Failed atomic replacement cleans up temporary files');

	$imagesDirectory = $testDirectory . '/images';
	mkdir($imagesDirectory);
	touch($imagesDirectory . '/Onion 2.JPG');
	touch($imagesDirectory . '/Onion 10.jpg');
	touch($imagesDirectory . '/ignore.heic');
	assertSameValue(['Onion 2.JPG', 'Onion 10.jpg'], getImageFiles($imagesDirectory), 'Image files are filtered and naturally sorted');

	file_put_contents($voteFile, json_encode(['concurrent.jpg' => 0], JSON_THROW_ON_ERROR));
	$workerCode = 'require $argv[1]; for ($i = 0; $i < (int) $argv[3]; $i++) { loadVoteCounts($argv[2], "concurrent.jpg"); }';
	$workers = [];
	for ($worker = 0; $worker < 4; $worker++) {
		$process = proc_open(
			[PHP_BINARY, '-r', $workerCode, __DIR__ . '/../votes.php', $voteFile, '20'],
			[
				0 => ['file', '/dev/null', 'r'],
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes
		);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start a concurrent vote worker.');
		}
		$workers[] = [$process, $pipes];
	}

	foreach ($workers as [$process, $pipes]) {
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) {
			throw new RuntimeException('Concurrent vote worker failed: ' . $stdout . $stderr);
		}
	}

	$counts = loadVoteCounts($voteFile);
	assertSameValue(80, $counts['concurrent.jpg'], 'Concurrent votes are not lost');

	echo "All vote tests passed.\n";
} finally {
	foreach (glob($testDirectory . '/*') ?: [] as $path) {
		if (is_dir($path)) {
			foreach (glob($path . '/*') ?: [] as $nestedPath) {
				unlink($nestedPath);
			}
			rmdir($path);
		} else {
			unlink($path);
		}
	}
	rmdir($testDirectory);
	$lockDirectory = dirname($lockPath);
	if (is_file($lockPath)) {
		unlink($lockPath);
	}
	if (is_dir($lockDirectory)) {
		rmdir($lockDirectory);
	}
}
