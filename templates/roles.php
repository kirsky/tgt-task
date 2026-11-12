<?php
/**
 * Agreement lists per role and the methodology for writing them.
 *
 * @var \App\Roles           $roles
 * @var array<string,mixed>  $portal
 */
use App\Web\Views;

?>
<h1 class="h4"><i class="bi bi-card-checklist me-2 text-primary"></i>Договорённости по ролям</h1>
<p class="text-body-secondary">Списки пишет HR вместе с руководителями — по тому, что компания действительно делает для новичка. Хранятся в <code>config/roles.php</code>.</p>

<div class="card mb-4">
  <div class="card-header"><h2 class="h6 mb-0 py-1"><i class="bi bi-lightbulb me-2 text-warning"></i>Как писать вопрос новичку — 5 правил</h2></div>
  <div class="card-body">
    <ol class="mb-0">
      <li class="mb-2"><b>О действии компании, а не о новичке.</b>
        <span class="text-success"><i class="bi bi-check-lg"></i> «Тебе выдали спецодежду?»</span>
        <span class="text-danger ms-2"><i class="bi bi-x-lg"></i> «Тебе нравится коллектив?»</span>
        <span class="text-danger ms-2"><i class="bi bi-x-lg"></i> «Справляешься с нормой?»</span></li>
      <li class="mb-2"><b>Ответ «Нет» должен быть безопасным</b> — не читаться как жалоба на конкретного человека или признание слабости.
        <span class="text-success"><i class="bi bi-check-lg"></i> «Ты знаешь, кто твой наставник и как с ним связаться?»</span>
        <span class="text-danger ms-2"><i class="bi bi-x-lg"></i> «Наставник тебе помогает?»</span></li>
      <li class="mb-2"><b>Проверяемо и исправимо</b> — ответственный может проверить и исправить примерно за сутки.</li>
      <li class="mb-2"><b>Только то, о чём компания действительно договаривается.</b> Не спрашивать о том, о чём не договаривались.</li>
      <li><b>Один вопрос — одна договорённость,</b> и задаётся в день, когда она уже должна быть выполнена.</li>
    </ol>
  </div>
</div>

<?php foreach ($roles->keys() as $k): $r = $roles->get($k); ?>
<div class="card mb-4">
  <div class="card-header">
    <h2 class="h6 mb-0 pt-1"><i class="bi bi-briefcase me-2 text-primary"></i><?= Views::e($r['title']) ?> · <?= Views::e($r['department']) ?></h2>
    <div class="small text-body-secondary pb-1"><i class="bi bi-clock me-1"></i>Рабочее время <?= Views::e($r['work_start']) ?>–<?= Views::e($r['work_end']) ?>, вопросы приходят в <?= Views::e($r['ask_at']) ?>; бот не пишет раньше чем за 2 часа до начала и позже чем через 2 часа после конца работы.</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead><tr><th class="ps-3">День</th><th>Вопрос новичку</th><th>Задача ответственному</th><th>Кто отвечает</th></tr></thead>
      <tbody>
      <?php foreach ($r['agreements'] as $a): ?>
        <tr><td class="ps-3"><span class="badge text-bg-primary"><?= (int) $a['day'] ?></span></td><td><?= Views::e($a['question']) ?></td><td><?= Views::e($a['task_title']) ?></td><td><?= Views::e($portal['owner_titles'][$a['owner']] ?? $a['owner']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach ?>
