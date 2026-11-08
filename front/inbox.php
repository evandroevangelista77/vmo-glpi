<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Measurement;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Outbox;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$me = (int) Session::getLoginUserID();

Menu::header('Pendências e avisos', 'inbox');
echo Menu::title('Pendências e avisos', 'Aprovações e validações que dependem de você, e o histórico de avisos (atribuições, atrasos, medições vencidas, mudanças).',
    Menu::actionForm('inbox_read', ['return' => '/front/inbox.php'], "<button class='btn btn-outline-secondary'><i class='ti ti-checks me-1'></i>Marcar tudo como lido</button>"));

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Aguardando a sua decisão</h3></div><div class='list-group list-group-flush'>";
$n = 0;
foreach (Access::visibleInitiatives(['status' => 'review']) as $i) {
    if (Initiative::canDecide($i)) {
        $n++;
        echo "<a class='list-group-item' href='" . $e(Menu::url('/front/initiative.php?id=' . $i['id'])) . "'><i class='ti ti-eye me-2'></i>" . $e($i['code'] . ' · ' . $i['name']) . " — proposta para aprovar</a>";
    }
}
foreach (Measurement::pendingFor() as $p) {
    $n++;
    echo "<a class='list-group-item' href='" . $e(Menu::url('/front/benefit.php?id=' . $p['benefit']['id'])) . "'><i class='ti ti-ruler-measure me-2'></i>" . $e($p['benefit']['name']) . " — medição " . $e(substr($p['measurement']['period'], 0, 7)) . " para validar</a>";
}
echo ($n ? '' : "<div class='list-group-item vmo-muted'>Nada pendente.</div>") . "</div></div>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>Avisos</h3></div><div class='list-group list-group-flush'>";
foreach (Outbox::forUser($me) as $o) {
    $href = $o['itemtype'] === 'Initiative' ? Menu::url('/front/initiative.php?id=' . $o['items_id']) : ($o['itemtype'] === 'Benefit' ? Menu::url('/front/benefit.php?id=' . $o['items_id']) : '');
    $row = "<div class='fw-bold'>" . ($o['read_at'] ? '' : "<span class='badge bg-primary me-1'>novo</span>") . $e($o['title']) . "</div><div class='small vmo-muted'>" . $e(Menu::datetime($o['date_creation'])) . ($o['body'] ? ' · ' . $e($o['body']) : '') . "</div>";
    echo $href ? "<a class='list-group-item' href='" . $e($href) . "'>$row</a>" : "<div class='list-group-item'>$row</div>";
}
echo "</div></div>";
Html::footer();
