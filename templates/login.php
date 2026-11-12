<?php
/**
 * Login form.
 *
 * @var string|null $error
 * @var string      $csrf
 */
use App\Web\Views;

?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-4">
    <div class="card mt-4">
      <div class="card-body p-4">
        <h1 class="h4 mb-2"><i class="bi bi-shield-lock me-2 text-primary"></i>Вход в панель HR</h1>
        <p class="text-body-secondary small">Договорённости с новичками на испытательном сроке: о чём компания договорилась с новичком на первые недели и что новичок уже подтвердил.</p>
        <form method="post" action="/login">
          <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
          <div class="mb-3">
            <label for="password" class="form-label">Пароль</label>
            <input type="password" class="form-control<?= $error ? ' is-invalid' : '' ?>" id="password" name="password" autofocus required>
            <?php if ($error): ?><div class="invalid-feedback"><?= Views::e($error) ?></div><?php endif ?>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i>Войти</button>
        </form>
      </div>
    </div>
  </div>
</div>
