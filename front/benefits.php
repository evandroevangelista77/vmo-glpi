<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Benefit;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Measurement;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);

Menu::header('Benefícios', 'benefits');
echo Menu::title('Benefícios', 'Plano de benefícios com dono e validador. Previsto, declarado e validado são valores distintos: entregar a iniciativa não comprova o benefício.',
    Access::canCreate() ? Menu::button('Novo benefício', Menu::url('/front/benefit.php?id=0')) : '');

$pending = Measurement::pendingFor();
if ($pending) {
    echo "<div class='card mb-3 border-warning'><div class='card-header'><h3 class='card-title'><i class='ti ti-hourglass me-1'></i>Medições aguardando a sua validação</h3></div><div class='list-group list-group-flush'>";
    foreach ($pending as $p) {
        echo "<a class='list-group-item' href='" . $e(Menu::url('/front/benefit.php?id=' . $p['benefit']['id'])) . "'>" . $e($p['benefit']['name']) . " — " . $e(substr($p['measurement']['period'], 0, 7)) . "</a>";
    }
    echo "</div></div>";
}

echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr><th>Benefício</th><th>Tipo</th><th>Situação</th><th>Dono</th><th>Validador</th><th>Horizonte</th><th>Medições vencidas</th></tr></thead><tbody>";
foreach (Benefit::visible() as $b) {
    $late = count(Benefit::overduePeriods($b));
    echo "<tr><td><a class='fw-bold' href='" . $e(Menu::url('/front/benefit.php?id=' . $b['id'])) . "'>" . $e($b['name']) . "</a></td><td>" . $e(Benefit::TYPES[$b['type']]) . ($b['currency'] ? ' · ' . $e($b['currency']) : '') . "</td>"
        . "<td>" . Menu::badge(Benefit::STATUSES[$b['status']][0], Benefit::STATUSES[$b['status']][1]) . "</td><td>" . $e(Db::userName((int) $b['users_id_owner'])) . "</td><td>" . $e(Db::userName((int) $b['users_id_validator'])) . "</td>"
        . "<td>" . $e(Menu::date($b['horizon_start'])) . " – " . $e(Menu::date($b['horizon_end'])) . "</td><td>" . ($late ? "<span class='badge bg-danger-lt'>$late</span>" : '—') . "</td></tr>";
}
echo "</tbody></table></div></div>";
Html::footer();
