<?php
require_once __DIR__ . '/votes.php';

$images = getImageFiles(__DIR__ . '/images');

try {
	$voteFile = voteStoragePath();
	$voteCounts = loadVoteCounts($voteFile);
} catch (JsonException | RuntimeException $exception) {
	error_log('Vote storage error in ' . $voteFile . ': ' . $exception->getMessage());
	http_response_code(500);
	exit('The high scores could not be loaded right now. Please try again later.');
}

usort($images, static function (string $left, string $right) use ($voteCounts): int {
	$voteDifference = ($voteCounts[$right] ?? 0) <=> ($voteCounts[$left] ?? 0);
	return $voteDifference !== 0 ? $voteDifference : strnatcasecmp($left, $right);
});

$totalVotes = totalVotesForImages($voteCounts, $images);
?>
<!doctype html>
<html lang="sv">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#f0d8b3">
	<title>Topplista — Sjyssta lökar</title>
	<link rel="stylesheet" href="styles.css">
</head>
<body>
	<div class="page-shell">
		<header class="topbar">
			<div class="brand"><span class="brand-mark" aria-hidden="true"></span> Sjyssta lökar</div>
			<nav class="site-nav" aria-label="Huvudmeny">
				<a href="index.php">Rösta</a>
				<a href="highscore.php" aria-current="page">Topplista</a>
			</nav>
		</header>

		<main>
			<section class="intro" aria-labelledby="page-title">
				<div>
					<p class="eyebrow">Publikens favoriter</p>
					<h1 id="page-title">Lökarnas<br>topplista</h1>
				</div>
				<p class="prompt">Alla bilder, sorterade efter antal röster från flest till minst.</p>
			</section>

			<?php if ($images !== []): ?>
				<p class="leaderboard-summary"><strong><?= count($images) ?></strong> bilder <span class="scoreboard-dot">/</span> <strong><?= (int) $totalVotes ?></strong> röster totalt</p>
				<ol class="leaderboard">
					<?php foreach ($images as $index => $filename): ?>
						<?php $votes = (int) ($voteCounts[$filename] ?? 0); ?>
						<li class="leaderboard-row">
							<span class="leaderboard-rank" aria-label="Plats <?= $index + 1 ?>"><?= $index + 1 ?></span>
							<img class="leaderboard-image" src="<?= escapeHtml(thumbnailUrl($filename)) ?>" alt="" loading="lazy">
							<span class="leaderboard-name"><?= escapeHtml($filename) ?></span>
							<span class="leaderboard-votes"><strong><?= $votes ?></strong> röst<?= $votes === 1 ? '' : 'er' ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php else: ?>
				<section class="empty-state" aria-live="polite">
					<h2>Inga bilder hittades ännu.</h2>
					<p>Lägg till bilder i <code>images/</code>-mappen så visas de här.</p>
				</section>
			<?php endif; ?>

			<footer class="scoreboard">
				<a class="text-link" href="index.php">Tillbaka och rösta</a>
				<span>Röster från alla besökare<span class="dot">/</span>Listan uppdateras när sidan laddas</span>
			</footer>
		</main>
	</div>
</body>
</html>
