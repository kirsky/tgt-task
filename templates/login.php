<?php
/**
 * Login form.
 *
 * @var string|null $error
 * @var string      $csrf
 */
use App\Web\Views;

?>
<section class="login">
  <h1>Вход в панель HR</h1>
  <p class="muted">Договорённости с новичками на испытательном сроке: о чём компания договорилась с новичком на первые недели и что новичок уже подтвердил.</p>
  <form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
    <label>Пароль <input type="password" name="password" autofocus required></label>
    <?php if ($error): ?><p class="err"><?= Views::e($error) ?></p><?php endif ?>
    <button>Войти</button>
  </form>
</section>
