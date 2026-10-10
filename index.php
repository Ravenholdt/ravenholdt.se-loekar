<?php
session_start();

$imageDirectory = __DIR__ . '/images';
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];
$images = [];

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

if (!isset($_SESSION['votes'])) {
	$_SESSION['votes'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['current_pair'])) {
	$pair = $_SESSION['current_pair'];
	$choice = $_POST['choice'] ?? '';

	if (in_array($choice, $pair, true) && in_array($choice, $images, true)) {
		$_SESSION['votes'][$choice] = ($_SESSION['votes'][$choice] ?? 0) + 1;
		$feedback = $choice;
	}
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

$totalVotes = array_sum($_SESSION['votes']);
?>
<!doctype html>
<html lang="en">
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
						<span><?= (int) ($_SESSION['votes'][$feedback] ?? 0) ?> röst<?= ($_SESSION['votes'][$feedback] ?? 0) === 1 ? '' : 'er' ?> för den här bilden</span>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<section class="empty-state" aria-live="polite">
					<h2><?= count($images) === 0 ? 'Inga allium hittades ännu.' : 'En lök till behövs.' ?></h2>
					<p>Lägg till minst två JPG-, PNG-, GIF-, WebP- eller AVIF-filer i <code>images/</code>-mappen och uppdatera sidan.</p>
				</section>
			<?php endif; ?>

			<footer class="scoreboard">
				<span><strong><?= (int) $totalVotes ?></strong> röst<?= $totalVotes === 1 ? '' : 'er' ?> under denna session</span>
				<span>Lökbilder hämtas från <code>images/</code><span class="dot">/</span>Sessionens totals nollställs när källaren stängs</span>
			</footer>
		</main>
	</div>
</body>
</html>
