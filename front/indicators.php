<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Indicator;
use GlpiPlugin\Vmo\Menu;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$form = function (string $do, array $hidden, string $inner, string $btn) use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'><input type='hidden' name='return' value='/front/indicators.php'>$h$inner<button class='btn btn-primary btn-sm mt-2'>" . $e($btn) . "</button></form>";
};
$field = fn (string $label, string $input) => "<div><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";

Menu::header('Indicadores', 'indicators');
echo Menu::title('Indicadores e metas', 'Dado ausente aparece como "sem dado" e dado vencido como "desatualizado": a ausência nunca é convertida em zero. Cumprimento = (observado − baseline) ÷ (meta − baseline).');

$items = Indicator::visible();
echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Código</th><th>Indicador</th><th>Direção</th><th>Baseline</th><th>Meta</th><th>Atual</th><th>Cumprimento</th><th>Situação</th><th></th></tr></thead><tbody>";
foreach ($items as $i) {
    $s = Indicator::state($i);
    $badge = ['nodata' => ['sem dado', '#94a3b8'], 'stale' => ['desatualizado', '#f59e0b'], 'ok' => ['atualizado', '#22c55e']][$s['status']];
    echo "<tr><td>" . $e($i['code']) . "</td><td><b>" . $e($i['name']) . "</b><div class='small vmo-muted'>" . $e($i['unit']) . ((int) $i['initiatives_id'] ? ' · ' . $e(Db::row('initiatives', (int) $i['initiatives_id'])['code'] ?? '') : ' · global') . "</div></td>"
        . "<td>" . ($i['direction'] === 'reduce' ? 'Reduzir' : 'Aumentar') . "</td><td>" . $e($i['baseline_value'] ?? '—') . "</td><td>" . $e($i['target'] ?? '—') . "</td>"
        . "<td>" . ($s['value'] === null ? "<span class='vmo-muted'>sem dado</span>" : $e(rtrim(rtrim(number_format($s['value'], 4, ',', '.'), '0'), ',')) . "<div class='small vmo-muted'>" . $e(Menu::date($s['last'])) . "</div>") . "</td>"
        . "<td>" . Menu::pct($s['progress'], 'sem dado') . "</td><td>" . Menu::badge($badge[0], $badge[1]) . "</td><td>";
    if (Access::canUpdate()) {
        echo "<details class='vmo-inline'><summary>Registrar medição</summary>" . $form('indicator_value', ['indicators_id' => $i['id']], $field('Data do período', $input('period', Db::today(), "type='date'")) . $field('Valor', $input('observed', '', 'inputmode=decimal required')), 'Registrar') . "</details>";
    }
    echo "</td></tr>";
}
if (!$items) {
    echo "<tr><td colspan='9' class='text-center text-secondary py-4'>Nenhum indicador cadastrado.</td></tr>";
}
echo "</tbody></table></div></div>";

if (Access::canCreate()) {
    $iopts = [0 => 'Global (portfólio)'];
    foreach (Access::visibleInitiatives() as $r) {
        $iopts[$r['id']] = $r['code'] . ' · ' . $r['name'];
    }
    $inner = "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . $field('Unidade', $input('unit'))
        . $field('Direção desejada', Menu::select('direction', ['increase' => 'Aumentar', 'reduce' => 'Reduzir'], 'increase')) . $field('Baseline', $input('baseline_value', '', 'inputmode=decimal')) . $field('Meta', $input('target', '', 'inputmode=decimal'))
        . $field('Cadência (dias)', $input('cadence_days', '30', 'type=number')) . $field('Fonte', $input('source')) . $field('Dono', Menu::userSelect('users_id_owner')) . $field('Iniciativa', Menu::select('initiatives_id', $iopts, 0)) . '</div>';
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Novo indicador</h3></div><div class='card-body'>" . $form('indicator_save', [], $inner, 'Criar indicador') . "</div></div>";
}
Html::footer();
