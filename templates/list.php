<?php
/**
 * Main page: what this is, demo bar, KPIs, newcomers on probation.
 *
 * @var array        $kpi
 * @var list<array>  $newcomers
 * @var \App\Roles   $roles
 * @var string       $demoName
 * @var string       $today
 * @var string       $csrf
 * @var bool         $demo
 * @var bool         $readonly
 */
use App\Web\Views;

?>
<p class="lead">Каждая договорённость с новичком — задача ответственному в Битрикс24. Новичок в Telegram подтверждает, что получил то, о чём договорились («Да / Частично / Нет»). Если задачу закрыли, а новичок не получил — разбирается HR.</p>

<?php if ($demo): ?>
<section class="demo">
  <b>Демо</b> · примите вымышленного сотрудника — в Битрикс24 появится задача «Приём», а у вас — личная ссылка на бота.
  <form method="post" action="/demo/hire" class="row">
    <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
    <input name="full_name" value="<?= Views::e($demoName) ?>" required aria-label="Фамилия Имя">
    <select name="role" aria-label="Должность">
      <?php foreach ($roles->keys() as $k): ?><option value="<?= Views::e($k) ?>"><?= Views::e($roles->get($k)['title']) ?></option><?php endforeach ?>
    </select>
    <input type="date" name="start_date" value="<?= Views::e($today) ?>" required aria-label="Дата выхода">
    <button <?= $readonly ? 'disabled title="Действия с задачами временно недоступны"' : '' ?>>➕ Принять нового сотрудника</button>
  </form>
</section>
<?php endif ?>

<section class="kpis">
  <div class="kpi"><div class="v"><?= $kpi['onTimePct'] === null ? '—' : $kpi['onTimePct'] . '%' ?></div><div class="l">договорённостей подтверждено в срок (30 дн.)</div></div>
  <div class="kpi <?= $kpi['overdue'] ? 'bad' : '' ?>"><div class="v"><?= $kpi['overdue'] ?></div><div class="l">просрочено</div></div>
  <div class="kpi <?= $kpi['closedNotReceived'] ? 'bad' : '' ?>"><div class="v"><?= $kpi['closedNotReceived'] ?></div><div class="l">закрыто, но не получено</div></div>
  <div class="kpi <?= $kpi['notConnected'] ? 'warn' : '' ?>"><div class="v"><?= $kpi['notConnected'] ?></div><div class="l">не подключились к боту</div></div>
</section>

<section class="card-box">
  <h2>На испытательном сроке · <?= count($newcomers) ?></h2>
  <?php if (!$newcomers): ?><p class="muted">Пока никого. Примите сотрудника в демо-панели выше.</p><?php endif ?>
  <table class="list">
    <?php foreach ($newcomers as $n): $role = $roles->get($n['role_key']); ?>
    <tr>
      <td><a href="/n/<?= (int) $n['id'] ?>"><b><?= Views::e($n['full_name']) ?></b></a><br><span class="muted"><?= Views::e($role['title']) ?> · <?= Views::e($role['department']) ?></span></td>
      <td class="day"><?= $n['day'] < 1 ? 'выход ' . Views::e(date('d.m', strtotime($n['start_date']))) : 'день ' . (int) $n['day'] . '/90' ?>
        <div class="bar"><span style="width:<?= max(0, min(100, (int) round($n['day'] / 90 * 100))) ?>%"></span></div></td>
      <td><?php foreach ($n['pills'] as $p): ?><span class="pill <?= $p['tone'] ?>"><?= Views::e($p['text']) ?></span><?php endforeach ?></td>
    </tr>
    <?php endforeach ?>
  </table>
</section>
