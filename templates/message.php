<?php
/**
 * Simple message page (404, Bitrix24 unavailable).
 *
 * @var string $title
 * @var string $message
 */
use App\Web\Views;

?>
<section class="card-box">
  <h1><?= Views::e($title) ?></h1>
  <p><?= Views::e($message) ?></p>
  <p><a href="/">← К списку</a></p>
</section>
