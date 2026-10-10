<?php
session_start();

$voteStorageRoot = is_dir('/var/tmp') && is_writable('/var/tmp') ? '/var/tmp' : sys_get_temp_dir();
$voteStorageDirectory = $voteStorageRoot . '/sjyssta-lokar-' . hash('sha256', __DIR__);
$imageDirectory = __DIR__ . '/images';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
$images = [];

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

if (is_dir($imageDirectory)) {
	foreach ($allowedExtensions as $extension) {
		foreach (glob($imageDirectory . '/*.' . $extension) ?: [] as $imagePath) {
			if (is_file($imagePath)) {
				$images[] = basename($imagePath);
			}
		}
	}
}

sort($images, SORT_NATURAL | SORT_FLAG_CASE);
$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['current_pair'])) {
	$pair = $_SESSION['current_pair'];
	$choice = $_POST['choice'] ?? '';

	if (is_string($choice) && in_array($choice, $pair, true) && in_array($choice, $images, true)) {
		$feedback = $choice;
	}
}

try {
	$voteCounts = loadVoteCounts($voteStorageDirectory, $feedback);
} catch (JsonException | RuntimeException $exception) {
	error_log('Vote storage error in ' . $voteStorageDirectory . ': ' . $exception->getMessage());
	http_response_code(500);
	exit('Votes could not be saved right now. Please try again later.');
}

if (count($images) >= 2) {
	$pair = $_SESSION['current_pair'] ?? [];
	if ($_SERVER['REQUEST_METHOD'] === 'POST' || count($pair) !== 2 || !in_array($pair[0], $images, true) || !in_array($pair[1], $images, true)) {
		$pair = array_rand(array_flip($images), 2);
		$pair = array_values($pair);
	}
	$_SESSION['current_pair'] = $pair;
} else {
	$pair = [];
	unset($_SESSION['current_pair']);
}

function escapeHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function imageUrl(string $filename): string
{
	return 'images/' . rawurlencode($filename);
}

$totalVotes = array_sum($voteCounts);
?>
<!doctype html>
<html lang="sv">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#f0d8b3">
	<title>Sjyssta lökar — Bildduell</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<div class="page-shell">
		<header class="topbar">
			<div class="brand"><span class="brand-mark" aria-hidden="true"></span> Sjyssta lökar</div>
			<div class="round-count">Bästa löken</div>
		</header>

		<main>
			<section class="intro" aria-labelledby="page-title">
				<div>
					<p class="eyebrow">Ett litet smakprov</p>
					<h1 id="page-title">Vilken lök<br>skär mest?</h1>
				</div>
				<p class="prompt">Två fina lökar. Ett val. Välj den bild som känns mest sjysst.</p>
			</section>

			<?php if (count($pair) === 2): ?>
				<form method="post" class="comparison" aria-label="Välj den bättre bilden">
					<?php foreach ($pair as $index => $filename): ?>
						<button class="image-choice" type="submit" name="choice" value="<?= escapeHtml($filename) ?>" aria-label="Rösta på bild <?= $index + 1 ?>">
							<img src="<?= escapeHtml(imageUrl($filename)) ?>" alt="Bild <?= $index + 1 ?>">
							<span class="choice-label" aria-hidden="true"><?= $index === 0 ? 'A' : 'B' ?></span>
							<span class="image-caption">
								<strong>Lök <?= $index === 0 ? '01' : '02' ?></strong>
								<span><?= escapeHtml(pathinfo($filename, PATHINFO_EXTENSION)) ?></span>
							</span>
						</button>
					<?php endforeach; ?>
					<span class="versus" aria-hidden="true">VS</span>
				</form>

				<?php if ($feedback !== null): ?>
					<div class="feedback" role="status">
						<span><strong>Röst registrerad.</strong> Ditt val är klart.</span>
						<span><?= (int) ($voteCounts[$feedback] ?? 0) ?> röst<?= ($voteCounts[$feedback] ?? 0) === 1 ? '' : 'er' ?> totalt för den här bilden</span>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<section class="empty-state" aria-live="polite">
					<h2><?= count($images) === 0 ? 'Inga allium hittades ännu.' : 'En lök till behövs.' ?></h2>
					<p>Lägg till minst två JPG-, PNG-, GIF-, WebP- eller AVIF-filer i <code>images/</code>-mappen och uppdatera sidan.</p>
				</section>
			<?php endif; ?>

			<footer class="scoreboard">
				<span><strong><?= (int) $totalVotes ?></strong> röst<?= $totalVotes === 1 ? '' : 'er' ?> totalt från alla besökare</span>
				<span>Röster sparas lokalt på servern<span class="dot">/</span>Lökbilder hämtas från <code>images/</code></span>
			</footer>
		</main>
	</div>
</body>
</html>
