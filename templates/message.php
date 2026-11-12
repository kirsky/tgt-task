<?php
/**
 * Simple message page (404, Bitrix24 unavailable).
 *
 * @var string $title
 * @var string $message
 */
use App\Web\Views;

?>
<div class="card shadow-sm">
  <div class="card-body">
    <h1 class="h4"><?= Views::e($title) ?></h1>
    <p class="mb-3"><?= Views::e($message) ?></p>
    <a href="/" class="btn btn-outline-primary btn-sm">← К списку</a>
  </div>
</div>
