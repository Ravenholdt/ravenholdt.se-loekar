<?php
session_start();

require_once __DIR__ . '/votes.php';

$voteFile = voteStoragePath();
$imageDirectory = __DIR__ . '/images';
$images = getImageFiles($imageDirectory);
$feedback = null;
$rateLimited = false;
$cooldownRemaining = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['current_pair'])) {
	$pair = $_SESSION['current_pair'];
	$choice = $_POST['choice'] ?? '';

	if (is_string($choice) && in_array($choice, $pair, true) && in_array($choice, $images, true)) {
		$lastVoteAt = isset($_SESSION['last_vote_at']) && is_numeric($_SESSION['last_vote_at'])
			? (float) $_SESSION['last_vote_at']
			: null;
		$cooldownRemaining = voteRateLimitRemaining($lastVoteAt, microtime(true));
		if ($cooldownRemaining > 0) {
			$rateLimited = true;
		} else {
			$feedback = $choice;
		}
	}
}

try {
	$voteCounts = loadVoteCounts($voteFile, $feedback);
} catch (JsonException | RuntimeException $exception) {
	error_log('Vote storage error in ' . $voteFile . ': ' . $exception->getMessage());
	http_response_code(500);
	exit('Votes could not be saved right now. Please try again later.');
}

if ($feedback !== null) {
	$_SESSION['last_vote_at'] = microtime(true);
}

if (count($images) >= 2) {
	$pair = $_SESSION['current_pair'] ?? [];
	if (($_SERVER['REQUEST_METHOD'] === 'POST' && !$rateLimited) || count($pair) !== 2 || !in_array($pair[0], $images, true) || !in_array($pair[1], $images, true)) {
		$pair = array_rand(array_flip($images), 2);
		$pair = array_values($pair);
	}
	$_SESSION['current_pair'] = $pair;
} else {
	$pair = [];
	unset($_SESSION['current_pair']);
}

$totalVotes = totalVotesForImages($voteCounts, $images);
session_write_close();
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
			<nav class="site-nav" aria-label="Huvudmeny">
				<a href="index.php" aria-current="page">Rösta</a>
				<a href="highscore.php">Topplista</a>
			</nav>
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
				<?php elseif ($rateLimited): ?>
					<div class="feedback feedback--warning" role="status">
						<span><strong>Vänta lite.</strong> Du kan rösta igen om <?= $cooldownRemaining ?> sekund<?= $cooldownRemaining === 1 ? '' : 'er' ?>.</span>
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
