<?php
/**
 * Agreement lists per role and the methodology for writing them.
 *
 * @var \App\Roles $roles
 * @var array      $portal
 */
use App\Web\Views;

?>
<h1 class="h4">Договорённости по ролям</h1>
<p class="text-body-secondary">Списки пишет HR вместе с руководителями — по тому, что компания действительно делает для новичка. Хранятся в <code>config/roles.php</code>.</p>

<div class="card shadow-sm mb-4">
  <div class="card-header bg-body"><h2 class="h6 mb-0">Как писать вопрос новичку — 5 правил</h2></div>
  <div class="card-body">
    <ol class="mb-0">
      <li class="mb-2"><b>О действии компании, а не о новичке.</b> ✅ «Тебе выдали спецодежду?» ❌ «Тебе нравится коллектив?», ❌ «Справляешься с нормой?»</li>
      <li class="mb-2"><b>Ответ «Нет» должен быть безопасным</b> — не читаться как жалоба на конкретного человека или признание слабости. ✅ «Ты знаешь, кто твой наставник и как с ним связаться?» ❌ «Наставник тебе помогает?»</li>
      <li class="mb-2"><b>Проверяемо и исправимо</b> — ответственный может проверить и исправить примерно за сутки.</li>
      <li class="mb-2"><b>Только то, о чём компания действительно договаривается.</b> Не спрашивать о том, о чём не договаривались.</li>
      <li><b>Один вопрос — одна договорённость,</b> и задаётся в день, когда она уже должна быть выполнена.</li>
    </ol>
  </div>
</div>

<?php foreach ($roles->keys() as $k): $r = $roles->get($k); ?>
<div class="card shadow-sm mb-4">
  <div class="card-header bg-body">
    <h2 class="h6 mb-0"><?= Views::e($r['title']) ?> · <?= Views::e($r['department']) ?></h2>
    <div class="small text-body-secondary">Рабочее время <?= Views::e($r['work_start']) ?>–<?= Views::e($r['work_end']) ?>, вопросы приходят в <?= Views::e($r['ask_at']) ?>; бот не пишет раньше чем за 2 часа до начала и позже чем через 2 часа после конца работы.</div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th class="ps-3">День</th><th>Вопрос новичку</th><th>Задача ответственному</th><th>Кто отвечает</th></tr></thead>
      <tbody>
      <?php foreach ($r['agreements'] as $a): ?>
        <tr><td class="ps-3"><?= (int) $a['day'] ?></td><td><?= Views::e($a['question']) ?></td><td><?= Views::e($a['task_title']) ?></td><td><?= Views::e($portal['owner_titles'][$a['owner']] ?? $a['owner']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach ?>
