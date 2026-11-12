<?php
/**
 * Page shell (Bootstrap 5): navbar with product name, read-only banner, confirmation
 * message of the previous action, content, footer. Assets are built by webpack into
 * public/build (npm run build).
 *
 * @var string      $title
 * @var string      $content
 * @var string      $csrf
 * @var bool        $authed
 * @var bool        $readonly
 * @var string|null $flash
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
<nav class="navbar navbar-expand-md navbar-glass sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2" href="/">
      <span class="brand-mark"><i class="bi bi-person-check"></i></span>
      <span><b>Новичок</b> <span class="brand-tagline">· как договаривались</span></span>
    </a>
    <?php if (!empty($authed)): ?>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Меню">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="/"><i class="bi bi-people me-1"></i>На испытательном</a></li>
        <li class="nav-item"><a class="nav-link" href="/roles"><i class="bi bi-card-checklist me-1"></i>Договорённости по ролям</a></li>
      </ul>
      <form method="post" action="/logout" class="d-flex">
        <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
        <button type="submit" class="btn btn-link nav-link"><i class="bi bi-box-arrow-right me-1"></i>Выйти</button>
      </form>
    </div>
    <?php endif ?>
  </div>
</nav>
<?php if (!empty($readonly)): ?>
<div class="alert alert-warning rounded-0 text-center mb-0 fw-semibold"><i class="bi bi-cloud-slash me-2"></i>Битрикс24 недоступен — панель в режиме просмотра. Действия с задачами временно недоступны.</div>
<?php endif ?>
<main class="container py-4">
  <?php if (!empty($flash)): ?>
  <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-check-circle-fill mt-1"></i><div><?= Views::e($flash) ?></div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
  </div>
  <?php endif ?>
  <?= $content ?>
</main>
<footer class="container pb-4 text-center small text-body-secondary">Демо для тестового задания · все люди и данные вымышленные</footer>
</body>
</html>
