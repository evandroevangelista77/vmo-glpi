<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = fn ($v) => Menu::e($v);
$q = trim((string) ($_GET['q'] ?? ''));
$status = isset(Initiative::STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$health = isset(Menu::HEALTH[$_GET['health'] ?? '']) ? $_GET['health'] : '';
$manager = (int) ($_GET['manager'] ?? 0);
$late = !empty($_GET['late']);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50; // RNF13: paginação padrão 50

$where = [];
if ($status) {
    $where['status'] = $status;
}
if ($manager) {
    $where['users_id_manager'] = $manager;
}
$rows = Access::visibleInitiatives($where);
if ($q !== '') {
    $rows = array_filter($rows, fn ($r) => stripos($r['code'] . ' ' . $r['name'] . ' ' . $r['description'], $q) !== false);
}
$metrics = Initiative::metricsFor($rows, null, Access::canFinance());
if ($health) {
    $rows = array_filter($rows, fn ($r) => $metrics[(int) $r['id']]['health'] === $health);
}
if ($late) {
    $rows = array_filter($rows, fn ($r) => ($metrics[(int) $r['id']]['delay'] ?? 0) > 0);
}
// ordenação estável: código, depois id
uasort($rows, fn ($a, $b) => [$a['code'], $a['id']] <=> [$b['code'], $b['id']]);
$total = count($rows);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$slice = array_slice($rows, ($page - 1) * $perPage, $perPage, true);

Menu::header('Iniciativas', 'initiatives');
echo Menu::title('Portfólio de iniciativas', 'Do rascunho à comprovação de valor. Os totais e as listas consideram o seu acesso (entidade, confidencialidade e perfil).',
    Access::canCreate() ? Menu::button('Nova iniciativa', Menu::url('/front/initiative.php?id=0')) : '');

echo "<form method='get' class='card card-body mb-3 d-flex flex-row flex-wrap gap-2 align-items-end'>"
    . "<div class='flex-grow-1' style='min-width:220px'><label class='form-label mb-1'>Buscar</label><input class='form-control' type='search' name='q' value='" . $e($q) . "' placeholder='Código, título ou descrição'></div>"
    . "<div><label class='form-label mb-1'>Situação</label>" . Menu::select('status', array_map(fn ($s) => $s[0], Initiative::STATUSES), $status, '', true) . "</div>"
    . "<div><label class='form-label mb-1'>Saúde</label>" . Menu::select('health', array_map(fn ($s) => $s[0], Menu::HEALTH), $health, '', true) . "</div>"
    . "<div><label class='form-label mb-1'>Gestor</label>" . Menu::userSelect('manager', $manager) . "</div>"
    . "<div class='form-check mb-2'><input class='form-check-input' type='checkbox' name='late' value='1' id='late'" . ($late ? ' checked' : '') . "><label class='form-check-label' for='late'>Só atrasadas</label></div>"
    . "<button class='btn btn-primary'><i class='ti ti-search me-1'></i>Filtrar</button></form>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>$total iniciativa(s)</h3></div><div class='table-responsive'><table class='table table-vcenter card-table table-hover'>"
    . "<thead><tr><th>Código</th><th>Iniciativa</th><th>Situação</th><th>Saúde</th><th style='min-width:120px'>Progresso</th><th>Atraso</th><th>Gestor</th><th>Término</th></tr></thead><tbody>";
foreach ($slice as $id => $r) {
    $m = $metrics[$id];
    $prog = $m['progress'];
    echo "<tr><td class='text-nowrap'><a href='" . $e(Menu::url('/front/initiative.php?id=' . $id)) . "'>" . $e($r['code']) . "</a>" . ($r['confidentiality'] ? " <i class='ti ti-lock text-secondary' title='Confidencial'></i>" : '') . "</td>"
        . "<td><a href='" . $e(Menu::url('/front/initiative.php?id=' . $id)) . "' class='fw-bold text-reset'>" . $e($r['name']) . "</a>"
        . ($m['stale'] ? " <span class='badge bg-warning-lt' title='Sem atualização além da cadência'>desatualizada</span>" : '') . "</td>"
        . "<td>" . Initiative::statusBadge($r['status']) . "</td><td>" . Menu::healthBadge($m['health']) . "</td>"
        . "<td>" . ($prog === null ? "<span class='vmo-muted'>sem dado</span>" : "<div class='vmo-bar'><span style='width:" . min(100, $prog) . "%'></span></div><small>" . number_format($prog, 1, ',', '.') . "%</small>") . "</td>"
        . "<td>" . ($m['delay'] === null ? "<span class='vmo-muted'>—</span>" : $m['delay'] . ' d.ú.') . "</td>"
        . "<td>" . $e(Db::userName((int) $r['users_id_manager'])) . "</td><td>" . $e(Menu::date($r['forecast_end'] ?: $r['planned_end'])) . "</td></tr>";
}
if (!$slice) {
    echo "<tr><td colspan='8' class='text-center text-secondary py-4'>Nenhuma iniciativa com esses filtros" . (Access::canCreate() ? '. Crie a primeira em "Nova iniciativa".' : '.') . "</td></tr>";
}
echo "</tbody></table></div>";
if ($pages > 1) {
    $base = $_GET;
    echo "<div class='card-footer d-flex gap-2 justify-content-center'>";
    for ($i = 1; $i <= $pages; $i++) {
        $base['page'] = $i;
        echo "<a class='btn btn-sm " . ($i === $page ? 'btn-primary' : 'btn-outline-secondary') . "' href='?" . $e(http_build_query($base)) . "'>$i</a>";
    }
    echo "</div>";
}
echo "</div>";
Html::footer();
