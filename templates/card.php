<?php
/**
 * Newcomer card: invite link and QR, agreements by day with answers and Bitrix24 status.
 *
 * @var array                 $n
 * @var array                 $role
 * @var int                   $day
 * @var list<array>           $agreements
 * @var array<int,list<array>> $answers
 * @var array<int,int>|null   $live
 * @var \App\Bitrix\Tasks     $tasks
 * @var string|null           $link
 * @var string|null           $qr
 * @var bool                  $canNext
 * @var string                $csrf
 * @var bool                  $demo
 * @var bool                  $readonly
 */
use App\Domain\Answer;
use App\Web\Labels;
use App\Web\Views;

$labelOf = fn (string $a) => Answer::from($a)->label();
?>
<p><a href="/">← Все новички</a></p>
<section class="card-box head">
  <div>
    <h1><?= Views::e($n['full_name']) ?></h1>
    <p class="muted"><?= Views::e($role['title']) ?> · <?= Views::e($role['department']) ?> · выход <?= Views::e(date('d.m.Y', strtotime($n['start_date']))) ?> · <?= $day < 1 ? 'ещё не вышел' : 'день ' . $day . '/90' ?>
      <?php if ($n['is_example']): ?><span class="pill wait">пример</span><?php endif ?></p>
    <?php if ($link): ?>
      <p>Личная ссылка на бота: <a href="<?= Views::e($link) ?>" target="_blank" rel="noopener"><?= Views::e($link) ?></a></p>
      <p class="muted"><?= $n['tg_chat_id'] ? '✓ Бот подключён' : 'Бот ещё не подключён — отправьте ссылку или QR новичку.' ?></p>
    <?php elseif ($n['is_example']): ?>
      <p class="muted">Это пример для наглядности: без задач в Битрикс24 и без бота.</p>
    <?php endif ?>
    <?php if ($demo && $canNext): ?>
      <form method="post" action="/demo/next/<?= (int) $n['id'] ?>"><input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
        <button <?= $readonly ? 'disabled' : '' ?>>⏩ Следующий опрос сейчас</button> <span class="muted">демо: как будто наступил следующий день с вопросами</span></form>
    <?php endif ?>
  </div>
  <?php if ($qr): ?><div class="qr"><?= $qr ?></div><?php endif ?>
</section>

<section class="card-box">
  <h2>Договорённости</h2>
  <?php if ($live === null && !$n['is_example']): ?><p class="badge">Статусы из Битрикс24 сейчас недоступны</p><?php endif ?>
  <table class="list agreements">
    <?php foreach ($agreements as $a): $def = $a['def']; ?>
    <tr>
      <td class="day">День <?= (int) $a['day'] ?></td>
      <td>
        <b><?= Views::e($def['question']) ?></b>
        <div class="muted">Задача: <?= Views::e($def['task_title']) ?>
          <?php if ($a['bitrix_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['bitrix_task_id'])) ?>" target="_blank" rel="noopener">в Битрикс24</a>
            <?php if ($live !== null): ?> (<?= Views::e(Labels::bitrixStatus($live[(int) $a['bitrix_task_id']] ?? null)) ?>)<?php endif ?>
          <?php endif ?>
          <?php if ($a['dispute_task_id']): ?> · <a href="<?= Views::e($tasks->url((int) $a['dispute_task_id'])) ?>" target="_blank" rel="noopener">задача HR</a><?php endif ?>
        </div>
        <?php foreach ($answers[$a['id']] ?? [] as $ans): ?>
          <div class="hist"><?= Views::e(date('d.m H:i', strtotime($ans['created_at']))) ?> · <?= $ans['kind'] === 'recheck' ? 'после закрытия: ' : '' ?>«<?= Views::e($labelOf($ans['answer'])) ?>»<?= $ans['note'] !== null ? ': «' . Views::e($ans['note']) . '»' : '' ?></div>
        <?php endforeach ?>
      </td>
      <td class="st">
        <span class="pill <?= $a['label']['tone'] ?>"><?= Views::e($a['label']['text']) ?></span>
        <?php if ($demo && !$n['is_example'] && $a['bitrix_task_id'] && in_array($a['state'], ['asked', 'not_received', 'no_answer', 'scheduled'], true)): ?>
          <form method="post" action="/demo/close/<?= (int) $a['id'] ?>"><input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
            <button class="small" <?= $readonly ? 'disabled' : '' ?> title="Демо: завершить задачу в Битрикс24 от имени ответственного">✔ Закрыть как ответственный</button></form>
        <?php endif ?>
      </td>
    </tr>
    <?php endforeach ?>
  </table>
</section>
