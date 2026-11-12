<?php
/**
 * Page shell (Bootstrap 5): navbar with product name, read-only banner, content, footer.
 * Assets are built by webpack into public/build (npm run build).
 *
 * @var string $title
 * @var string $content
 * @var string $csrf
 * @var bool   $authed
 * @var bool   $readonly
 */
use App\Web\Views;

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= Views::e($title) ?> · Новичок</title>
<script>document.documentElement.setAttribute('data-bs-theme', matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');</script>
<link rel="stylesheet" href="/build/app.css">
<script src="/build/app.js" defer></script>
</head>
<body>
<nav class="navbar navbar-expand-md bg-body border-bottom">
  <div class="container">
    <a class="navbar-brand" href="/"><b>Новичок</b> <span class="brand-tagline">· как договаривались</span></a>
    <?php if (!empty($authed)): ?>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Меню">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/">На испытательном</a></li>
        <li class="nav-item"><a class="nav-link" href="/roles">Договорённости по ролям</a></li>
      </ul>
      <form method="post" action="/logout" class="d-flex">
        <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
        <button type="submit" class="btn btn-link nav-link">Выйти</button>
      </form>
    </div>
    <?php endif ?>
  </div>
</nav>
<?php if (!empty($readonly)): ?>
<div class="alert alert-warning rounded-0 text-center mb-0 fw-semibold">Битрикс24 недоступен — панель в режиме просмотра. Действия с задачами временно недоступны.</div>
<?php endif ?>
<main class="container py-4"><?= $content ?></main>
<footer class="container pb-4 text-center small text-body-secondary">Демо для тестового задания · все люди и данные вымышленные</footer>
</body>
</html>
