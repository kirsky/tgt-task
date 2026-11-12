<?php
/**
 * Newcomer card: invite link (with copy) and QR, agreements by day with answers and
 * Bitrix24 status, demo actions.
 *
 * @var array<string,mixed>            $n
 * @var array<string,mixed>            $role
 * @var int                            $day
 * @var list<array<string,mixed>>      $agreements
 * @var array<int,list<array<string,mixed>>> $answers
 * @var array<int,int>|null            $live
 * @var \App\Bitrix\Tasks              $tasks
 * @var string|null                    $link
 * @var string|null                    $qr
 * @var bool                           $canNext
 * @var string                         $csrf
 * @var bool                           $demo
 * @var bool                           $readonly
 */
use App\Domain\Answer;
use App\Web\Labels;
use App\Web\Views;

$labelOf = fn (string $a) => Answer::from($a)->label();
$dis = $readonly ? ' disabled title="Действия с задачами временно недоступны"' : '';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/"><i class="bi bi-people me-1"></i>Все новички</a></li><li class="breadcrumb-item active"><?= Views::e($n['full_name']) ?></li></ol></nav>

<div class="card mb-4">
  <div class="card-body d-flex flex-wrap gap-4 justify-content-between">
    <div class="flex-grow-1" style="min-width: 18rem; max-width: 42rem">
      <h1 class="h4 mb-1"><?= Views::e($n['full_name']) ?> <?php if ($n['is_example']): ?><span class="<?= Views::badge('wait') ?> align-middle fs-6">пример</span><?php endif ?></h1>
      <p class="text-body-secondary mb-3"><i class="bi bi-briefcase me-1"></i><?= Views::e($role['title']) ?> · <?= Views::e($role['department']) ?> · выход <?= Views::e(date('d.m.Y', (int) strtotime($n['start_date']))) ?> · <?= $day < 1 ? 'ещё не вышел' : 'день ' . $day . '/90' ?></p>

      <?php if ($link): ?>
        <label for="invite-link" class="form-label small text-body-secondary mb-1">Личная ссылка на бота для новичка</label>
        <div class="input-group mb-2">
          <input id="invite-link" type="text" class="form-control" value="<?= Views::e($link) ?>" readonly>
          <button type="button" class="btn btn-outline-primary" data-copy="#invite-link"><i class="bi bi-clipboard me-1"></i><span class="copy-label">Скопировать</span></button>
          <a class="btn btn-outline-secondary" href="<?= Views::e($link) ?>" target="_blank" rel="noopener" title="Открыть в Telegram"><i class="bi bi-telegram"></i></a>
        </div>
        <?php if ($n['tg_chat_id']): ?>
          <p class="small text-success mb-3"><i class="bi bi-check-circle-fill me-1"></i>Бот подключён</p>
        <?php else: ?>
          <p class="small text-warning-emphasis mb-3"><i class="bi bi-hourglass-split me-1"></i>Бот ещё не подключён. Вопросы начнут приходить после того, как новичок откроет ссылку или отсканирует QR-код.</p>
        <?php endif ?>
      <?php elseif ($n['is_example']): ?>
        <p class="small text-body-secondary"><i class="bi bi-info-circle me-1"></i>Это пример для наглядности: без задач в Битрикс24 и без бота.</p>
      <?php endif ?>

      <?php if ($demo && $canNext): ?>
        <form method="post" action="/demo/next/<?= (int) $n['id'] ?>" class="d-flex flex-wrap align-items-center gap-2">
          <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
          <button type="submit" class="btn btn-primary btn-sm"<?= $dis ?>><i class="bi bi-skip-forward-fill me-1"></i>Следующий опрос сейчас</button>
          <span class="small text-body-secondary">демо: как будто наступил следующий день с вопросами</span>
        </form>
      <?php endif ?>
    </div>
    <?php if ($qr): ?><div class="qr text-center"><?= $qr ?><div class="small text-body-secondary mt-1">QR для новичка</div></div><?php endif ?>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0 py-1"><i class="bi bi-card-checklist me-2 text-primary"></i>Договорённости</h2>
    <?php if ($live === null && !$n['is_example']): ?><span class="<?= Views::badge('warn') ?>"><i class="bi bi-cloud-slash me-1"></i>Статусы из Битрикс24 сейчас недоступны</span><?php endif ?>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($agreements as $a): $def = $a['def']; ?>
    <li class="list-group-item py-3">
      <div class="row g-2">
        <div class="col-md-1 small text-body-secondary text-nowrap">День <?= (int) $a['day'] ?></div>
        <div class="col-md-8">
          <div class="fw-semibold"><?= Views::e($def['question']) ?></div>
          <div class="small text-body-secondary">Задача: <?= Views::e($def['task_title']) ?>
            <?php if ($a['bitrix_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['bitrix_task_id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>в Битрикс24</a>
              <?php if ($live !== null): ?>(<?= Views::e(Labels::bitrixStatus($live[(int) $a['bitrix_task_id']] ?? null)) ?>)<?php endif ?>
            <?php endif ?>
            <?php if ($a['dispute_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['dispute_task_id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-person-badge me-1"></i>задача HR</a><?php endif ?>
          </div>
          <?php foreach ($answers[$a['id']] ?? [] as $ans): ?>
            <div class="history"><i class="bi bi-chat-left-text me-1"></i><?= Views::e(date('d.m H:i', (int) strtotime($ans['created_at']))) ?> · <?= $ans['kind'] === 'recheck' ? 'после закрытия: ' : '' ?>«<?= Views::e($labelOf($ans['answer'])) ?>»<?= $ans['note'] !== null ? ': «' . Views::e($ans['note']) . '»' : '' ?></div>
          <?php endforeach ?>
        </div>
        <div class="col-md-3 text-md-end">
          <?= Views::status($a['label']) ?>
          <?php if ($demo && !$n['is_example'] && $a['bitrix_task_id'] && in_array($a['state'], ['asked', 'not_received', 'no_answer', 'scheduled'], true)): ?>
            <form method="post" action="/demo/close/<?= (int) $a['id'] ?>" class="mt-2">
              <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
              <button type="submit" class="btn btn-outline-secondary btn-sm"<?= $dis ?> title="Демо: завершить задачу в Битрикс24 от имени ответственного"><i class="bi bi-check2-square me-1"></i>Закрыть как ответственный</button>
            </form>
          <?php endif ?>
        </div>
      </div>
    </li>
    <?php endforeach ?>
  </ul>
</div>
