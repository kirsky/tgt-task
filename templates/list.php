<?php
/**
 * Main page: what this is, demo bar, KPIs, newcomers on probation.
 *
 * @var array{onTimePct: ?int, overdue: int, closedNotReceived: int, notConnected: int} $kpi
 * @var list<array<string,mixed>> $newcomers
 * @var \App\Roles   $roles
 * @var string       $demoName
 * @var string       $today
 * @var string       $csrf
 * @var bool         $demo
 * @var bool         $readonly
 */
use App\Web\Views;

$dis = $readonly ? ' disabled title="Действия с задачами временно недоступны"' : '';
$tiles = [
    ['bi-check2-circle', $kpi['onTimePct'] === null ? '—' : $kpi['onTimePct'] . '%', 'договорённостей подтверждено в срок (30 дн.)', 'kpi-primary'],
    ['bi-alarm', $kpi['overdue'], 'просрочено', $kpi['overdue'] ? 'kpi-danger' : 'kpi-muted'],
    ['bi-exclamation-octagon', $kpi['closedNotReceived'], 'закрыто, но не получено', $kpi['closedNotReceived'] ? 'kpi-danger' : 'kpi-muted'],
    ['bi-telegram', $kpi['notConnected'], 'не подключились к боту', $kpi['notConnected'] ? 'kpi-warning' : 'kpi-muted'],
];
?>
<div class="mb-4">
  <h1 class="h3 page-title mb-1">На испытательном сроке</h1>
  <p class="text-body-secondary mb-0">Каждая договорённость с новичком — задача ответственному в Битрикс24. Новичок в Telegram подтверждает, что получил то, о чём договорились («Да / Частично / Нет»). Если задачу закрыли, а новичок не получил — разбирается HR.</p>
</div>

<?php if ($demo): ?>
<div class="card demo-bar mb-4">
  <div class="card-body">
    <p class="mb-3"><span class="badge text-bg-warning me-2"><i class="bi bi-play-circle me-1"></i>Демо</span>Примите вымышленного сотрудника — в Битрикс24 появится задача «Приём», а у вас — личная ссылка на бота.</p>
    <form method="post" action="/demo/hire" class="row g-2 align-items-center">
      <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
      <div class="col-md-4"><input class="form-control" name="full_name" value="<?= Views::e($demoName) ?>" required aria-label="Фамилия Имя"></div>
      <div class="col-md-3">
        <select class="form-select" name="role" aria-label="Должность">
          <?php foreach ($roles->keys() as $k): ?><option value="<?= Views::e($k) ?>"><?= Views::e($roles->get($k)['title']) ?></option><?php endforeach ?>
        </select>
      </div>
      <div class="col-md-2"><input class="form-control" type="date" name="start_date" value="<?= Views::e($today) ?>" required aria-label="Дата выхода"></div>
      <div class="col-md-3 d-grid"><button type="submit" class="btn btn-primary"<?= $dis ?>><i class="bi bi-person-plus me-1"></i>Принять нового сотрудника</button></div>
    </form>
    <form method="post" action="/demo/reset-examples" class="mt-2 small text-body-secondary">
      <input type="hidden" name="csrf" value="<?= Views::e($csrf) ?>">
      <button type="submit" class="btn btn-link btn-sm p-0 align-baseline"><i class="bi bi-arrow-counterclockwise me-1"></i>Сбросить примеры</button>
      · «пример» — вымышленные новички в разных ситуациях, без задач в Битрикс24; обновляются каждый день.
    </form>
  </div>
</div>
<?php endif ?>

<div class="row g-3 mb-4">
  <?php foreach ($tiles as [$icon, $value, $caption, $cls]): ?>
  <div class="col-6 col-lg-3">
    <div class="card kpi h-100 <?= $cls ?>"><div class="card-body d-flex gap-3 align-items-start">
      <span class="kpi-icon"><i class="bi <?= $icon ?> fs-5"></i></span>
      <div><div class="value"><?= Views::e($value) ?></div><div class="caption"><?= Views::e($caption) ?></div></div>
    </div></div>
  </div>
  <?php endforeach ?>
</div>

<div class="card">
  <div class="card-header"><h2 class="h6 mb-0 py-1"><i class="bi bi-people me-2 text-primary"></i>Новички · <?= count($newcomers) ?></h2></div>
  <?php if (!$newcomers): ?><div class="card-body text-body-secondary">Пока никого. Примите сотрудника в демо-панели выше.</div><?php endif ?>
  <div class="list-group list-group-flush">
    <?php foreach ($newcomers as $n): $role = $roles->get($n['role_key']); ?>
    <a href="/n/<?= (int) $n['id'] ?>" class="list-group-item list-group-item-action py-3">
      <div class="row align-items-center g-2">
        <div class="col-md-4">
          <div class="fw-semibold"><?= Views::e($n['full_name']) ?></div>
          <div class="small text-body-secondary"><?= Views::e($role['title']) ?> · <?= Views::e($role['department']) ?></div>
        </div>
        <div class="col-md-2 small text-body-secondary">
          <?= $n['day'] < 1 ? 'выход ' . Views::e(date('d.m', (int) strtotime($n['start_date']))) : 'день ' . (int) $n['day'] . '/90' ?>
          <div class="progress probation mt-1" role="progressbar" aria-label="испытательный срок"><div class="progress-bar" style="width:<?= max(0, min(100, (int) round($n['day'] / 90 * 100))) ?>%"></div></div>
        </div>
        <div class="col-md-6 d-flex flex-wrap gap-1">
          <?php foreach ($n['pills'] as $p): ?><span class="<?= Views::badge($p['tone']) ?>"><?= Views::e($p['text']) ?></span><?php endforeach ?>
        </div>
      </div>
    </a>
    <?php endforeach ?>
  </div>
</div>
