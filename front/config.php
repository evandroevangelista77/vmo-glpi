<?php

/**
 * Configuração e diagnóstico do VMO (RF01, RF24): parâmetros, limites de saúde versionados,
 * catálogos, departamentos, câmbio, delegações e verificação de dependências e rotinas.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView() || !Access::canConfigure()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);

// exportação da configuração (sem segredos)
if (!empty($_GET['export'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="vmo-configuracao.json"');
    echo json_encode([
        'plugin' => 'vmo', 'version' => PLUGIN_VMO_VERSION, 'currency' => Settings::currency(), 'cadence_days' => Settings::cadenceDays(), 'holidays' => Settings::holidays(),
        'report_retention_days' => Settings::reportRetentionDays(), 'audit_retention_months' => Settings::auditRetentionMonths(),
        'thresholds' => Settings::thresholds(), 'thresholds_version' => Settings::thresholdsVersion(),
        'catalogs' => array_map(fn ($c) => ['kind' => $c['kind'], 'code' => $c['code'], 'name' => $c['name'], 'active' => (bool) $c['is_active']], Db::rows('catalogs')),
        'departments' => array_map(fn ($d) => ['code' => $d['code'], 'name' => $d['name'], 'active' => (bool) $d['is_active']], Department::all()),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

$form = function (string $do, array $hidden, string $inner, string $btn = 'Salvar') use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'><input type='hidden' name='return' value='/front/config.php'>$h$inner"
        . ($btn !== '' ? "<button class='btn btn-primary btn-sm mt-2'>" . $e($btn) . "</button>" : '') . "</form>";
};
$field = fn (string $label, string $input) => "<div><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";

Menu::header('Configuração', 'config');
echo Menu::title('Configuração do VMO', 'Parâmetros por instalação, limites de saúde versionados, catálogos, departamentos, câmbio, delegações e diagnóstico.',
    Menu::button('Exportar configuração (sem segredos)', Menu::url('/front/config.php?export=1'), 'ti-download', 'btn-outline-secondary'));

// ---- parâmetros gerais + limites
$th = Settings::thresholds();
$labels = ['delay_red' => 'Atraso crítico (> dias úteis)', 'delay_yellow' => 'Atraso de atenção (> dias úteis)', 'cost_red' => 'Desvio de custo crítico (> %)', 'cost_yellow' => 'Desvio de custo de atenção (> %)',
    'risk_red' => 'Risco crítico (exposição ≥)', 'risk_yellow' => 'Risco de atenção (exposição ≥)'];
echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Parâmetros gerais</h3></div><div class='card-body'>"
    . $form('config_settings', [], "<div class='vmo-grid2'>" . $field('Moeda funcional (ISO)', $input('currency', Settings::currency(), 'maxlength=3')) . $field('Cadência de atualização (dias)', $input('cadence_days', Settings::cadenceDays(), 'type=number min=1'))
        . $field('Retenção dos relatórios (dias)', $input('report_retention_days', Settings::reportRetentionDays(), 'type=number min=1')) . $field('Retenção da auditoria (meses)', $input('audit_retention_months', Settings::auditRetentionMonths(), 'type=number min=1')) . '</div>'
        . "<div class='mt-2'>" . $field('Feriados (AAAA-MM-DD, separados por vírgula ou linha) — usados nos dias úteis', "<textarea class='form-control' name='holidays' rows='3'>" . $e(implode(', ', Settings::holidays())) . "</textarea>") . '</div>')
    . "</div></div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Limites de saúde (versão " . Settings::thresholdsVersion() . ")</h3></div><div class='card-body'>";
$inner = "<div class='vmo-grid2'>";
foreach ($th as $k => $v) {
    $inner .= $field($labels[$k], $input("th[$k]", $v, 'inputmode=decimal'));
}
echo $form('config_thresholds', [], $inner . '</div>', 'Salvar nova versão')
    . "<div class='form-hint mt-2'>Mudar um limite cria uma nova versão: cálculos novos usam a vigente; baselines e relatórios anteriores mantêm a versão com que foram gerados.</div></div></div></div></div>";

// ---- catálogos
echo "<div class='row'>";
foreach (Catalog::KINDS as $kind => $title) {
    echo "<div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>" . $e($title) . "</h3></div><div class='table-responsive'><table class='table card-table'><tbody>";
    foreach (Catalog::all($kind, false) as $c) {
        echo "<tr class='" . ($c['is_active'] ? '' : 'opacity-50') . "'><td>" . $e($c['code']) . "</td><td>" . $e($c['name']) . "</td><td class='text-end'>"
            . Menu::actionForm('catalog_toggle', ['id' => $c['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-secondary'>" . ($c['is_active'] ? 'Desativar' : 'Ativar') . "</button>") . "</td></tr>";
    }
    echo "</tbody></table></div><div class='card-body border-top'>" . $form('catalog_add', ['kind' => $kind], "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . '</div>', 'Adicionar') . "</div></div></div>";
}
echo "</div>";

// ---- departamentos
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Departamentos</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Código</th><th>Nome</th><th>Responsável</th><th></th></tr></thead><tbody>";
foreach (Department::all() as $d) {
    echo "<tr class='" . ($d['is_active'] ? '' : 'opacity-50') . "'><td>" . $e($d['code']) . "</td><td>" . $e($d['name']) . "</td><td>" . $e(Db::userName((int) $d['users_id_responsible'])) . "</td><td class='text-end'>"
        . Menu::actionForm('dept_toggle', ['id' => $d['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-secondary'>" . ($d['is_active'] ? 'Desativar' : 'Ativar') . "</button>") . "</td></tr>";
}
echo "</tbody></table></div><div class='card-body border-top'>" . $form('dept_add', [], "<div class='vmo-grid2'>" . $field('Código', $input('code', '', 'required')) . $field('Nome', $input('name', '', 'required')) . $field('Responsável', Menu::userSelect('users_id_responsible')) . '</div>', 'Adicionar departamento') . "</div></div>";

// ---- câmbio e delegações
echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Taxas de câmbio</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Par</th><th>Data</th><th>Taxa</th><th>Fonte</th></tr></thead><tbody>";
foreach (Db::rows('fx_rates', [], ['rate_date DESC'], 30) as $r) {
    echo "<tr><td>" . $e($r['from_currency'] . '/' . $r['to_currency']) . "</td><td>" . $e(Menu::date($r['rate_date'])) . "</td><td>" . $e(rtrim(rtrim($r['rate'], '0'), '.')) . "</td><td>" . $e($r['source']) . "</td></tr>";
}
echo "</tbody></table></div><div class='card-body border-top'>" . $form('fx_add', [], "<div class='vmo-grid2'>" . $field('De', $input('from_currency', 'USD', 'maxlength=3')) . $field('Para', $input('to_currency', Settings::currency(), 'maxlength=3'))
    . $field('Data', $input('rate_date', Db::today(), "type='date'")) . $field('Taxa', $input('rate', '', 'inputmode=decimal required')) . $field('Fonte', $input('source', '', 'required placeholder="ex.: PTAX Banco Central"')) . '</div>', 'Registrar taxa')
    . "<div class='form-hint mt-2'>Sem taxa registrada, valores de moedas diferentes não são somados: aparecem em totais separados.</div></div></div></div>";
echo "<div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Delegações de alçada</h3></div><div class='table-responsive'><table class='table card-table'><tbody>";
foreach (Db::rows('delegations', [], ['id DESC'], 30) as $d) {
    echo "<tr><td>" . $e(Db::userName((int) $d['users_id_from'])) . " → " . $e(Db::userName((int) $d['users_id_to'])) . "</td><td>" . $e(Menu::date($d['valid_from'])) . " a " . $e(Menu::date($d['valid_to'])) . "</td><td class='text-end'>"
        . Menu::actionForm('delegation_remove', ['id' => $d['id'], 'return' => '/front/config.php'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") . "</td></tr>";
}
echo "</tbody></table></div><div class='card-body border-top'>" . $form('delegation_add', [], "<div class='vmo-grid2'>" . $field('Titular', Menu::userSelect('users_id_from')) . $field('Substituto', Menu::userSelect('users_id_to'))
    . $field('Vigência: início', $input('valid_from', Db::today(), "type='date'")) . $field('Vigência: fim', $input('valid_to', Db::today(), "type='date'")) . $field('Motivo', $input('reason')) . '</div>', 'Cadastrar delegação') . "</div></div></div></div>";

// ---- diagnóstico (RF24)
global $DB;
$exts = ['zip' => 'DOCX dos relatórios', 'mbstring' => 'textos UTF-8', 'gd' => 'gráficos/PDF', 'json' => 'JSON'];
echo "<div class='card'><div class='card-header'><h3 class='card-title'>Diagnóstico</h3></div><div class='card-body'><table class='table table-sm'><tbody>"
    . "<tr><td>Versão do plugin</td><td>" . $e(PLUGIN_VMO_VERSION) . " (fórmulas v" . $e(Calc::FORMULA_VERSION) . ")</td></tr>"
    . "<tr><td>GLPI</td><td>" . $e(GLPI_VERSION) . " (suportado: " . $e(PLUGIN_VMO_MIN_GLPI . ' a ' . PLUGIN_VMO_MAX_GLPI) . ")</td></tr>"
    . "<tr><td>PHP</td><td>" . $e(PHP_VERSION) . "</td></tr>";
foreach ($exts as $x => $why) {
    echo "<tr><td>Extensão $x ($why)</td><td>" . (extension_loaded($x) ? "<span class='text-success'>ok</span>" : "<span class='text-danger'>ausente</span>") . "</td></tr>";
}
$missing = array_filter(PLUGIN_VMO_TABLES, fn ($t) => !$DB->tableExists(Db::t($t)));
echo "<tr><td>Tabelas do plugin</td><td>" . ($missing ? "<span class='text-danger'>faltando: " . $e(implode(', ', $missing)) . "</span>" : "<span class='text-success'>" . count(PLUGIN_VMO_TABLES) . " de " . count(PLUGIN_VMO_TABLES) . " presentes</span>") . "</td></tr>";
foreach ($DB->request(['FROM' => 'glpi_crontasks', 'WHERE' => ['itemtype' => 'GlpiPlugin\\Vmo\\Jobs']]) as $c) {
    echo "<tr><td>Rotina <code>" . $e($c['name']) . "</code></td><td>" . ($c['lastrun'] ? 'último ciclo ' . $e($c['lastrun']) : "<span class='text-warning'>ainda não executou</span>") . ' · ' . ((int) $c['state'] === 1 ? 'ativa' : 'desativada') . "</td></tr>";
}
echo "<tr><td>Eventos de auditoria</td><td>" . (int) countElementsInTable(Db::t('audit_events')) . " (retenção " . Settings::auditRetentionMonths() . " meses)</td></tr></tbody></table></div></div>";
Html::footer();
