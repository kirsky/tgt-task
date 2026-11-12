<?php
/**
 * Newcomer card: invite link and QR, agreements by day with answers and Bitrix24 status.
 *
 * @var array                  $n
 * @var array                  $role
 * @var int                    $day
 * @var list<array>            $agreements
 * @var array<int,list<array>> $answers
 * @var array<int,int>|null    $live
 * @var \App\Bitrix\Tasks      $tasks
 * @var string|null            $link
 * @var string|null            $qr
 * @var bool                   $canNext
 * @var string                 $csrf
 * @var bool                   $demo
 * @var bool                   $readonly
 */
use App\Domain\Answer;
use App\Web\Labels;
use App\Web\Views;

$labelOf = fn (string $a) => Answer::from($a)->label();
$dis = $readonly ? ' disabled title="Действия с задачами временно недоступны"' : '';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="/">Все новички</a></li><li class="breadcrumb-item active"><?= Views::e($n['full_name']) ?></li></ol></nav>

<div class="card shadow-sm mb-4">
  <div class="card-body d-flex flex-wrap gap-4 justify-content-between">
    <div>
      <h1 class="h4 mb-1"><?= Views::e($n['full_name']) ?> <?php if ($n['is_example']): ?><span class="<?= Views::badge('wait') ?> align-middle">пример</span><?php endif ?></h1>
      <p class="text-body-secondary mb-3"><?= Views::e($role['title']) ?> · <?= Views::e($role['department']) ?> · выход <?= Views::e(date('d.m.Y', strtotime($n['start_date']))) ?> · <?= $day < 1 ? 'ещё не вышел' : 'день ' . $day . '/90' ?></p>
      <?php if ($link): ?>
        <p class="mb-1">Личная ссылка на бота: <a href="<?= Views::e($link) ?>" target="_blank" rel="noopener"><?= Views::e($link) ?></a></p>
        <p class="small <?= $n['tg_chat_id'] ? 'text-success' : 'text-warning-emphasis' ?>"><?= $n['tg_chat_id'] ? '✓ Бот подключён' : 'Бот ещё не подключён — отправьте ссылку или QR новичку.' ?></p>
      <?php elseif ($n['is_example']): ?>
        <p class="small text-body-secondary">Это пример для наглядности: без задач в Битрикс24 и без бота.</p>
      <?php endif ?>
      <?php if ($demo && $canNext): ?>
        <form method="post" action="/demo/next/<?= (int) $n['id'] ?>" class="d-flex flex-wrap align-items-center gap-2">
          <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
          <button type="submit" class="btn btn-outline-primary btn-sm"<?= $dis ?>>⏩ Следующий опрос сейчас</button>
          <span class="small text-body-secondary">демо: как будто наступил следующий день с вопросами</span>
        </form>
      <?php endif ?>
    </div>
    <?php if ($qr): ?><div class="qr"><?= $qr ?></div><?php endif ?>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-body d-flex justify-content-between align-items-center">
    <h2 class="h6 mb-0">Договорённости</h2>
    <?php if ($live === null && !$n['is_example']): ?><span class="badge text-bg-warning">Статусы из Битрикс24 сейчас недоступны</span><?php endif ?>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($agreements as $a): $def = $a['def']; ?>
    <li class="list-group-item">
      <div class="row g-2">
        <div class="col-md-1 small text-body-secondary text-nowrap">День <?= (int) $a['day'] ?></div>
        <div class="col-md-8">
          <div class="fw-semibold"><?= Views::e($def['question']) ?></div>
          <div class="small text-body-secondary">Задача: <?= Views::e($def['task_title']) ?>
            <?php if ($a['bitrix_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['bitrix_task_id'])) ?>" target="_blank" rel="noopener">в Битрикс24</a>
              <?php if ($live !== null): ?>(<?= Views::e(Labels::bitrixStatus($live[(int) $a['bitrix_task_id']] ?? null)) ?>)<?php endif ?>
            <?php endif ?>
            <?php if ($a['dispute_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['dispute_task_id'])) ?>" target="_blank" rel="noopener">задача HR</a><?php endif ?>
          </div>
          <?php foreach ($answers[$a['id']] ?? [] as $ans): ?>
            <div class="history"><?= Views::e(date('d.m H:i', strtotime($ans['created_at']))) ?> · <?= $ans['kind'] === 'recheck' ? 'после закрытия: ' : '' ?>«<?= Views::e($labelOf($ans['answer'])) ?>»<?= $ans['note'] !== null ? ': «' . Views::e($ans['note']) . '»' : '' ?></div>
          <?php endforeach ?>
        </div>
        <div class="col-md-3 text-md-end">
          <span class="<?= Views::badge($a['label']['tone']) ?>"><?= Views::e($a['label']['text']) ?></span>
          <?php if ($demo && !$n['is_example'] && $a['bitrix_task_id'] && in_array($a['state'], ['asked', 'not_received', 'no_answer', 'scheduled'], true)): ?>
            <form method="post" action="/demo/close/<?= (int) $a['id'] ?>" class="mt-1">
              <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
              <button type="submit" class="btn btn-outline-secondary btn-sm"<?= $dis ?> title="Демо: завершить задачу в Битрикс24 от имени ответственного">✔ Закрыть как ответственный</button>
            </form>
          <?php endif ?>
        </div>
      </div>
    </li>
    <?php endforeach ?>
  </ul>
</div>
