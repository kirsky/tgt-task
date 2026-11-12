<?php
/**
 * Agreement lists per role and the methodology for writing them.
 *
 * @var \App\Roles $roles
 * @var array      $portal
 */
use App\Web\Views;

?>
<h1>Договорённости по ролям</h1>
<p class="lead">Списки пишет HR вместе с руководителями — по тому, что компания действительно делает для новичка. Хранятся в <code>config/roles.php</code>.</p>

<section class="card-box">
  <h2>Как писать вопрос новичку — 5 правил</h2>
  <ol class="rules">
    <li><b>О действии компании, а не о новичке.</b> ✅ «Тебе выдали спецодежду?» ❌ «Тебе нравится коллектив?», ❌ «Справляешься с нормой?»</li>
    <li><b>Ответ «Нет» должен быть безопасным</b> — не читаться как жалоба на конкретного человека или признание слабости. ✅ «Ты знаешь, кто твой наставник и как с ним связаться?» ❌ «Наставник тебе помогает?»</li>
    <li><b>Проверяемо и исправимо</b> — ответственный может проверить и исправить примерно за сутки.</li>
    <li><b>Только то, о чём компания действительно договаривается.</b> Не спрашивать о том, о чём не договаривались.</li>
    <li><b>Один вопрос — одна договорённость,</b> и задаётся в день, когда она уже должна быть выполнена.</li>
  </ol>
</section>

<?php foreach ($roles->keys() as $k): $r = $roles->get($k); ?>
<section class="card-box">
  <h2><?= Views::e($r['title']) ?> · <?= Views::e($r['department']) ?></h2>
  <p class="muted">Рабочее время <?= Views::e($r['work_start']) ?>–<?= Views::e($r['work_end']) ?>, вопросы приходят в <?= Views::e($r['ask_at']) ?>; бот не пишет раньше чем за 2 часа до начала и позже чем через 2 часа после конца работы.</p>
  <table class="list">
    <tr><th>День</th><th>Вопрос новичку</th><th>Задача ответственному</th><th>Кто отвечает</th></tr>
    <?php foreach ($r['agreements'] as $a): ?>
    <tr><td><?= (int) $a['day'] ?></td><td><?= Views::e($a['question']) ?></td><td><?= Views::e($a['task_title']) ?></td><td><?= Views::e($portal['owner_titles'][$a['owner']] ?? $a['owner']) ?></td></tr>
    <?php endforeach ?>
  </table>
</section>
<?php endforeach ?>
