<?php
/**
 * Page shell (Bootstrap 5): coloured app header with brand and section pills, read-only
 * banner, confirmation message of the previous action, content, footer. Assets are built
 * by webpack into public/build and referenced with a build version (see Views::asset()).
 *
 * @var string      $title
 * @var string      $content
 * @var string      $csrf
 * @var bool        $authed
 * @var bool        $readonly
 * @var string|null $flash
 * @var string      $active   Current section: `home`, `roles` or empty.
 */
use App\Web\Views;

$nav = [
    'home' => ['/', 'bi-people', 'На испытательном'],
    'roles' => ['/roles', 'bi-card-checklist', 'Договорённости по ролям'],
];
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= Views::e($title) ?> · Новичок</title>
<script>document.documentElement.setAttribute('data-bs-theme', matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');</script>
<link rel="stylesheet" href="<?= Views::e(Views::asset('app.css')) ?>">
<script src="<?= Views::e(Views::asset('app.js')) ?>" defer></script>
</head>
<body>
<header class="app-header">
  <div class="container d-flex flex-wrap align-items-center gap-3 py-3">
    <a class="brand d-flex align-items-center gap-2 me-lg-4 text-decoration-none" href="/">
      <span class="brand-mark"><i class="bi bi-person-check-fill"></i></span>
      <span class="lh-sm">
        <span class="brand-name d-block">Новичок</span>
        <span class="brand-tagline d-block">как договаривались</span>
      </span>
    </a>
    <?php if (!empty($authed)): ?>
    <ul class="nav nav-pills app-nav flex-nowrap">
      <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
      <li class="nav-item">
        <a class="nav-link<?= ($active ?? '') === $key ? ' active' : '' ?>" <?= ($active ?? '') === $key ? 'aria-current="page" ' : '' ?>href="<?= $href ?>"><i class="bi <?= $icon ?> me-1"></i><?= Views::e($label) ?></a>
      </li>
      <?php endforeach ?>
    </ul>
    <div class="app-actions ms-auto d-flex align-items-center gap-2">
      <span class="header-chip d-none d-sm-inline-flex"><i class="bi bi-person-badge me-1"></i>HR · демо</span>
      <form method="post" action="/logout" class="m-0">
        <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
        <button type="submit" class="btn btn-sm btn-light"><i class="bi bi-box-arrow-right me-1"></i>Выйти</button>
      </form>
    </div>
    <?php endif ?>
  </div>
</header>
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
