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
	<meta name="theme-color" content="#f4f2ec">
	<title>Frame / Frame — Image Face-off</title>
	<style>
		:root {
			color-scheme: light;
			--paper: #f4f2ec;
			--ink: #202522;
			--muted: #68716a;
			--line: #d8d9d1;
			--green: #315d4b;
			--coral: #d65d45;
			--sun: #e7bd61;
		}

		* { box-sizing: border-box; }

		body {
			margin: 0;
			min-width: 320px;
			background-color: var(--paper);
			background-image: radial-gradient(#68716a18 0.65px, transparent 0.65px);
			background-size: 8px 8px;
			color: var(--ink);
			font-family: "Avenir Next", Avenir, "Segoe UI", sans-serif;
		}

		.page-shell {
			width: min(1180px, calc(100% - 48px));
			margin: 0 auto;
		}

		.topbar {
			display: flex;
			justify-content: space-between;
			align-items: center;
			min-height: 78px;
			border-bottom: 1px solid var(--line);
		}

		.brand {
			display: flex;
			align-items: center;
			gap: 10px;
			font-size: 13px;
			font-weight: 800;
			text-transform: uppercase;
		}

		.brand-mark {
			display: grid;
			grid-template-columns: 9px 9px;
			gap: 3px;
			transform: rotate(-8deg);
		}

		.brand-mark i { width: 9px; height: 16px; background: var(--coral); }
		.brand-mark i:last-child { background: var(--green); margin-top: 4px; }

		.round-count {
			color: var(--muted);
			font-size: 12px;
			font-weight: 700;
			letter-spacing: 0;
			text-transform: uppercase;
		}

		main { padding: 56px 0 64px; }

		.intro {
			display: flex;
			justify-content: space-between;
			align-items: end;
			gap: 32px;
			margin-bottom: 30px;
		}

		.eyebrow {
			margin: 0 0 12px;
			color: var(--coral);
			font-size: 11px;
			font-weight: 800;
			text-transform: uppercase;
		}

		h1 {
			max-width: 650px;
			margin: 0;
			font-family: Georgia, "Times New Roman", serif;
			font-size: clamp(36px, 5vw, 60px);
			font-weight: 500;
			line-height: 1.02;
		}

		.prompt {
			max-width: 230px;
			margin: 0 0 4px;
			color: var(--muted);
			font-size: 14px;
			line-height: 1.55;
		}

		.comparison {
			position: relative;
			display: grid;
			grid-template-columns: repeat(2, minmax(0, 1fr));
			gap: 18px;
		}

		.image-choice {
			position: relative;
			display: block;
			width: 100%;
			overflow: hidden;
			padding: 0;
			border: 1px solid var(--line);
			border-radius: 4px;
			background: #e5e1d8;
			color: inherit;
			cursor: pointer;
			text-align: left;
		}

		.image-choice img {
			display: block;
			width: 100%;
			aspect-ratio: 1.36 / 1;
			object-fit: cover;
			transition: transform 220ms ease, filter 220ms ease;
		}

		.image-choice:hover img { transform: scale(1.025); }
		.image-choice:focus-visible { outline: 3px solid var(--coral); outline-offset: 3px; }

		.choice-label {
			position: absolute;
			top: 14px;
			left: 14px;
			display: grid;
			width: 36px;
			aspect-ratio: 1;
			place-items: center;
			border-radius: 50%;
			background: var(--paper);
			font-family: Georgia, "Times New Roman", serif;
			font-size: 17px;
		}

		.image-caption {
			display: flex;
			justify-content: space-between;
			align-items: center;
			min-height: 52px;
			padding: 0 16px;
			border-top: 1px solid var(--line);
			background: #fffdf8;
			font-size: 12px;
		}

		.image-caption strong { font-weight: 700; }
		.image-caption span:last-child { color: var(--muted); }

		.versus {
			position: absolute;
			z-index: 1;
			top: 50%;
			left: 50%;
			display: grid;
			width: 46px;
			aspect-ratio: 1;
			place-items: center;
			border: 1px solid var(--ink);
			border-radius: 50%;
			background: var(--sun);
			font-family: Georgia, "Times New Roman", serif;
			font-size: 12px;
			transform: translate(-50%, -50%);
		}

		.feedback {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
			margin-top: 24px;
			padding: 16px 18px;
			border-left: 3px solid var(--green);
			background: #e5ebe3;
			font-size: 14px;
		}

		.feedback strong { color: var(--green); }

		.empty-state {
			margin-top: 20px;
			padding: 30px;
			border: 1px dashed #a8afa6;
			border-radius: 4px;
			background: #fffdf8a8;
		}

		.empty-state h2 {
			margin: 0 0 8px;
			font-family: Georgia, "Times New Roman", serif;
			font-size: 25px;
			font-weight: 500;
		}

		.empty-state p { margin: 0; color: var(--muted); line-height: 1.6; }
		code { padding: 2px 5px; background: #e9e6dc; color: var(--ink); }

		.scoreboard {
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 20px;
			margin-top: 34px;
			padding-top: 18px;
			border-top: 1px solid var(--line);
			color: var(--muted);
			font-size: 12px;
		}

		.scoreboard strong { color: var(--ink); }
		.scoreboard .dot { color: var(--coral); padding: 0 7px; }

		@media (max-width: 680px) {
			.page-shell { width: min(100% - 28px, 520px); }
			.topbar { min-height: 64px; }
			main { padding-top: 36px; }
			.intro { display: block; margin-bottom: 22px; }
			h1 { font-size: 42px; }
			.prompt { max-width: none; margin-top: 12px; }
			.comparison { grid-template-columns: 1fr; gap: 12px; }
			.image-choice img { aspect-ratio: 1.45 / 1; }
			.versus { top: 50%; }
			.scoreboard { align-items: flex-start; flex-direction: column; gap: 8px; }
		}

		@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after { scroll-behavior: auto !important; transition-duration: 0.01ms !important; }
		}
	</style>
</head>
<body>
	<div class="page-shell">
		<header class="topbar">
			<div class="brand"><span class="brand-mark" aria-hidden="true"><i></i><i></i></span> Frame / Frame</div>
			<div class="round-count">The image face-off</div>
		</header>

		<main>
			<section class="intro" aria-labelledby="page-title">
				<div>
					<p class="eyebrow">A small test of taste</p>
					<h1 id="page-title">Which image<br>makes the cut?</h1>
				</div>
				<p class="prompt">Two images. One pick. Choose the one you would keep.</p>
			</section>

			<?php if (count($pair) === 2): ?>
				<form method="post" class="comparison" aria-label="Choose the better image">
					<?php foreach ($pair as $index => $filename): ?>
						<button class="image-choice" type="submit" name="choice" value="<?= escapeHtml($filename) ?>" aria-label="Vote for image <?= $index + 1 ?>">
							<img src="<?= escapeHtml(imageUrl($filename)) ?>" alt="Image <?= $index + 1 ?>">
							<span class="choice-label" aria-hidden="true"><?= $index === 0 ? 'A' : 'B' ?></span>
							<span class="image-caption">
								<strong>Frame <?= $index === 0 ? '01' : '02' ?></strong>
								<span><?= escapeHtml(pathinfo($filename, PATHINFO_EXTENSION)) ?></span>
							</span>
						</button>
					<?php endforeach; ?>
					<span class="versus" aria-hidden="true">VS</span>
				</form>

				<?php if ($feedback !== null): ?>
					<div class="feedback" role="status">
						<span><strong>Vote counted.</strong> Your pick is in.</span>
						<span><?= (int) ($_SESSION['votes'][$feedback] ?? 0) ?> vote<?= ($_SESSION['votes'][$feedback] ?? 0) === 1 ? '' : 's' ?> for this image</span>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<section class="empty-state" aria-live="polite">
					<h2><?= count($images) === 0 ? 'No images found yet.' : 'One more image needed.' ?></h2>
					<p>Add at least two JPG, PNG, GIF, WebP, or AVIF files to the <code>images/</code> folder, then refresh this page.</p>
				</section>
			<?php endif; ?>

			<footer class="scoreboard">
				<span><strong><?= (int) $totalVotes ?></strong> vote<?= $totalVotes === 1 ? '' : 's' ?> this session</span>
				<span>Images are drawn from <code>images/</code><span class="dot">/</span>Session totals reset when the browser session ends</span>
			</footer>
		</main>
	</div>
</body>
</html>
