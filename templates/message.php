<?php
/**
 * Simple message page (404, Bitrix24 unavailable).
 *
 * @var string $title
 * @var string $message
 */
use App\Web\Views;

?>
<div class="card">
  <div class="card-body">
    <h1 class="h4"><i class="bi bi-info-circle me-2 text-primary"></i><?= Views::e($title) ?></h1>
    <p class="mb-3"><?= Views::e($message) ?></p>
    <a href="/" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left me-1"></i>К списку</a>
  </div>
</div>
