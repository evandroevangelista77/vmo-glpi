<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Risk;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);

$visible = Access::visibleInitiatives();
$rows = $visible ? Db::rows('risks', ['initiatives_id' => array_keys($visible), 'is_deleted' => 0, 'status' => ['open', 'mitigating']], ['id DESC']) : [];
usort($rows, fn ($a, $b) => Calc::riskExposure((int) $b['probability'], (int) $b['impact']) <=> Calc::riskExposure((int) $a['probability'], (int) $a['impact']));

Menu::header('Riscos e decisões', 'risks');
echo Menu::title('Riscos e problemas abertos', 'Exposição = probabilidade × impacto (escala de 1 a 5). Só aparecem registros de iniciativas que você pode ver.');

// mapa de calor probabilidade × impacto
$heat = [];
foreach ($rows as $r) {
    $heat[(int) $r['probability']][(int) $r['impact']] = ($heat[(int) $r['probability']][(int) $r['impact']] ?? 0) + 1;
}
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Mapa de calor (quantidade de registros)</h3></div><div class='card-body'><table class='table table-bordered vmo-heat' style='max-width:520px'><thead><tr><th>Prob. \\ Impacto</th>";
for ($i = 1; $i <= 5; $i++) {
    echo "<th>$i</th>";
}
echo "</tr></thead><tbody>";
for ($p = 5; $p >= 1; $p--) {
    echo "<tr><th>$p</th>";
    for ($i = 1; $i <= 5; $i++) {
        $x = $p * $i;
        echo "<td style='background:" . Risk::exposureColor($x) . "' title='Exposição $x'>" . ($heat[$p][$i] ?? '') . "</td>";
    }
    echo "</tr>";
}
echo "</tbody></table></div></div>";

echo "<div class='card'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Exposição</th><th>Tipo</th><th>Título</th><th>Iniciativa</th><th>Responsável</th><th>Prazo</th><th>Situação</th></tr></thead><tbody>";
foreach ($rows as $r) {
    $x = Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
    $i = $visible[(int) $r['initiatives_id']];
    echo "<tr><td>" . Menu::badge((string) $x, Risk::exposureColor($x)) . "</td><td>" . $e(Risk::KINDS[$r['kind']]) . "</td><td>" . $e($r['title']) . "</td>"
        . "<td><a href='" . $e(Menu::url('/front/initiative.php?tab=risks&id=' . $i['id'])) . "'>" . $e($i['code']) . "</a></td><td>" . $e(Db::userName((int) $r['users_id_owner'])) . "</td><td>" . $e(Menu::date($r['due_date'])) . "</td><td>" . $e(Risk::STATUSES[$r['status']]) . "</td></tr>";
}
if (!$rows) {
    echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum risco aberto.</td></tr>";
}
echo "</tbody></table></div></div>";
Html::footer();
