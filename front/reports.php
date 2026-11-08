<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Report;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$field = fn (string $label, string $input) => "<div><label class='form-label'>" . $e($label) . "</label>$input</div>";

Menu::header('Relatórios', 'reports');
echo Menu::title('Relatórios executivos', 'Cada relatório grava um snapshot do corte escolhido: editar uma iniciativa depois não altera um relatório já gerado. Os arquivos informam corte, filtros, moeda e versões de fórmula.');

$inner = "<div class='vmo-grid2'>" . $field('Título', "<input class='form-control' name='title' value='Relatório executivo VMO' required>")
    . $field('Corte (data)', "<input class='form-control' type='date' name='cutoff' value='" . $e(Db::today()) . "'>")
    . $field('Situação', Menu::select('status', array_map(fn ($s) => $s[0], Initiative::STATUSES), '', '', true))
    . $field('Saúde', Menu::select('health', array_map(fn ($s) => $s[0], Menu::HEALTH), '', '', true))
    . $field('Gestor', Menu::userSelect('manager'))
    . $field('Planejado a partir de', "<input class='form-control' type='date' name='from'>") . $field('Planejado até', "<input class='form-control' type='date' name='to'>") . '</div>';
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Gerar relatório</h3></div><div class='card-body'><form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>"
    . Menu::csrf() . "<input type='hidden' name='do' value='report_generate'><input type='hidden' name='return' value='/front/reports.php'>$inner<button class='btn btn-primary mt-3'><i class='ti ti-file-analytics me-1'></i>Gerar snapshot</button></form></div></div>";

echo "<div class='card'><div class='card-header'><h3 class='card-title'>Relatórios disponíveis (retenção de " . Settings::reportRetentionDays() . " dias)</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>#</th><th>Título</th><th>Corte</th><th>Gerado em</th><th>Por</th><th>Hash</th><th>Baixar</th></tr></thead><tbody>";
foreach (Report::mine() as $s) {
    $u = Menu::url('/front/download.php?id=' . $s['id'] . '&format=');
    echo "<tr><td>" . (int) $s['id'] . "</td><td>" . $e($s['title']) . "</td><td>" . $e(substr($s['cutoff'], 0, 10)) . "</td><td>" . $e(Menu::datetime($s['date_creation'])) . "</td><td>" . $e(Db::userName((int) $s['users_id'])) . "</td>"
        . "<td><code>" . $e(substr($s['hash'], 0, 12)) . "</code></td><td class='text-nowrap'>"
        . "<a class='btn btn-sm btn-outline-primary' href='" . $e($u . 'pdf') . "'>PDF</a> <a class='btn btn-sm btn-outline-primary' href='" . $e($u . 'docx') . "'>DOCX</a> <a class='btn btn-sm btn-outline-primary' href='" . $e($u . 'csv') . "'>CSV</a></td></tr>";
}
echo "</tbody></table></div></div>";
Html::footer();
