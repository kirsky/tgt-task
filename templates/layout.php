<?php
/**
 * Page shell: header with product name, navigation, read-only banner, content.
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
<link rel="stylesheet" href="/app.css">
</head>
<body>
<header class="top">
  <a class="brand" href="/"><b>Новичок</b> <span>· как договаривались</span></a>
  <?php if (!empty($authed)): ?>
  <nav>
    <a href="/">На испытательном</a>
    <a href="/roles">Договорённости по ролям</a>
    <form method="post" action="/logout"><input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>"><button class="link">Выйти</button></form>
  </nav>
  <?php endif ?>
</header>
<?php if (!empty($readonly)): ?>
<div class="banner">Битрикс24 недоступен — панель в режиме просмотра. Действия с задачами временно недоступны.</div>
<?php endif ?>
<main><?= $content ?></main>
<footer>Демо для тестового задания · все люди и данные вымышленные</footer>
</body>
</html>
