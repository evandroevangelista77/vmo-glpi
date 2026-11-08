<?php

/**
 * Dashboard executivo (RF15). Todos os cartões detalham a própria composição; a soma do detalhe
 * concilia com o cartão, que usa o mesmo corte, moeda e filtros. Acesso aplicado antes de somar (RN09).
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Outbox;
use GlpiPlugin\Vmo\Report;
use GlpiPlugin\Vmo\Risk;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$e = fn ($v) => Menu::e($v);
$detail = (string) ($_GET['detail'] ?? '');
$finance = Access::canFinance();

$data = Report::portfolio([]);
$rows = $data['rows'];
$tot = $data['totals'];
$ids = array_column($rows, 'id');
$link = fn (string $d) => Menu::url('/front/dashboard.php?detail=' . $d);

Menu::header('Dashboard executivo', 'dashboard');
echo Menu::title('Dashboard executivo', 'Valor previsto e validado, investimento, saúde do portfólio e pendências. Atualizado em ' . date('d/m/Y H:i') . ' — clique em um cartão para ver a composição.',
    Menu::button('Gerar relatório', Menu::url('/front/reports.php'), 'ti-file-analytics', 'btn-outline-primary'));

$card = function (string $label, string $value, string $sub, string $color, string $key) use ($link, $e): string {
    return "<a class='vmo-kpi' style='--c:$color' href='" . $e($link($key)) . "'><div class='vmo-kpi-label'>" . $e($label) . "</div><div class='vmo-kpi-value'>$value</div><div class='vmo-kpi-sub'>" . $e($sub) . "</div></a>";
};
$sumMoney = function (?array $by): string {
    if ($by === null) {
        return '—';
    }
    if (!$by) {
        return '—';
    }
    return implode('<br>', array_map(fn ($c, $v) => Menu::money((float) $v, $c), array_keys($by), $by));
};

echo "<div class='vmo-kpis'>"
    . $card('Iniciativas no portfólio', (string) $tot['count'], count($tot['by_status']) . ' situação(ões)', '#6366f1', 'status')
    . $card('Em atenção ou críticas', (string) (($tot['by_health']['yellow'] ?? 0) + ($tot['by_health']['red'] ?? 0)), ($tot['by_health']['red'] ?? 0) . ' crítica(s)', '#ef4444', 'health');
if ($finance) {
    echo $card('Benefício previsto (aprovado)', $sumMoney($tot['value_planned']), 'soma única, sem duplicar benefícios compartilhados', '#0ea5e9', 'planned')
        . $card('Benefício validado', $sumMoney($tot['value_validated']), 'só medições validadas por outra pessoa', '#22c55e', 'validated')
        . $card('Investimento realizado', $sumMoney($tot['investment']), 'lançamentos reais por competência', '#f59e0b', 'investment');
}
$openRisks = 0;
foreach ($rows as $r) {
    $openRisks += ($r['max_risk'] ?? 0) >= (int) Settings::thresholds()['risk_yellow'] ? 1 : 0;
}
echo $card('Riscos relevantes', (string) $openRisks, 'iniciativas com exposição ≥ ' . (int) Settings::thresholds()['risk_yellow'], '#a855f7', 'risks')
    . $card('Avisos não lidos', (string) Outbox::unread((int) Session::getLoginUserID()), 'aprovações, validações e atrasos', '#14b8a6', 'inbox')
    . "</div>";

if ($detail === '') {
    // distribuição por saúde e situação
    echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Iniciativas por saúde</h3></div><div class='card-body'>";
    foreach (Menu::HEALTH as $k => [$label, $color]) {
        $n = $tot['by_health'][$k] ?? 0;
        $pct = $tot['count'] ? round(100 * $n / $tot['count']) : 0;
        echo "<div class='d-flex align-items-center gap-2 mb-2'><div style='width:110px'>" . Menu::healthBadge($k) . "</div><div class='flex-grow-1 vmo-bar'><span style='width:$pct%;background:$color'></span></div><div style='width:60px;text-align:right'>$n</div></div>";
    }
    echo "<div class='small vmo-muted'>Alternativa tabular: " . $e(implode(' · ', array_map(fn ($k) => Menu::HEALTH[$k][0] . ' ' . ($tot['by_health'][$k] ?? 0), array_keys(Menu::HEALTH)))) . "</div></div></div></div>";
    echo "<div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Iniciativas por estágio</h3></div><div class='card-body'>";
    foreach (Initiative::STATUSES as $k => [$label, $color]) {
        $n = $tot['by_status'][$k] ?? 0;
        $pct = $tot['count'] ? round(100 * $n / $tot['count']) : 0;
        echo "<div class='d-flex align-items-center gap-2 mb-2'><div style='width:140px'>" . Initiative::statusBadge($k) . "</div><div class='flex-grow-1 vmo-bar'><span style='width:$pct%;background:$color'></span></div><div style='width:60px;text-align:right'>$n</div></div>";
    }
    echo "</div></div></div></div>";

    // decisões pendentes
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Decisões pendentes para você</h3></div><div class='card-body'>";
    $pend = 0;
    foreach (Access::visibleInitiatives(['status' => 'review']) as $i) {
        if (Initiative::canDecide($i)) {
            $pend++;
            echo "<div><i class='ti ti-eye me-1'></i><a href='" . $e(Menu::url('/front/initiative.php?id=' . $i['id'])) . "'>" . $e($i['code'] . ' · ' . $i['name']) . "</a> — proposta aguardando decisão</div>";
        }
    }
    foreach (\GlpiPlugin\Vmo\Measurement::pendingFor() as $p) {
        $pend++;
        echo "<div><i class='ti ti-ruler-measure me-1'></i><a href='" . $e(Menu::url('/front/benefit.php?id=' . $p['benefit']['id'])) . "'>" . $e($p['benefit']['name']) . "</a> — medição de " . $e(substr($p['measurement']['period'], 0, 7)) . " aguardando validação</div>";
    }
    echo ($pend ? '' : "<span class='vmo-muted'>Nada pendente.</span>") . "</div></div>";
    Html::footer();
    return;
}

// ---------------------------------------------------------------- detalhe de um cartão
echo "<div class='card'><div class='card-header'><h3 class='card-title'>Composição: " . $e($detail) . "</h3><div class='ms-auto'>" . Menu::button('Voltar ao painel', Menu::url('/front/dashboard.php'), 'ti-arrow-left', 'btn-outline-secondary btn-sm') . "</div></div><div class='table-responsive'><table class='table card-table'>";
$sum = [];
switch ($detail) {
    case 'planned': case 'validated': case 'investment':
        if (!$finance) {
            echo "<tr><td>Sem o direito financeiro.</td></tr>";
            break;
        }
        $key = ['planned' => 'value_planned', 'validated' => 'value_validated', 'investment' => 'actual'][$detail];
        echo "<thead><tr><th>Iniciativa</th><th>Moeda</th><th class='text-end'>Valor</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            $v = $r[$key] ?? null;
            if ($v === null || (float) $v == 0.0) {
                continue;
            }
            $sum[$r['currency']] = ($sum[$r['currency']] ?? 0) + $v;
            echo "<tr><td><a href='" . $e(Menu::url('/front/initiative.php?id=' . $r['id'])) . "'>" . $e($r['code'] . ' · ' . $r['name']) . "</a></td><td>" . $e($r['currency']) . "</td><td class='text-end'>" . $e(Menu::money((float) $v, $r['currency'])) . "</td></tr>";
        }
        foreach ($sum as $c => $v) {
            echo "<tr class='fw-bold'><td colspan='2'>Total ($c)</td><td class='text-end'>" . $e(Menu::money($v, $c)) . "</td></tr>";
        }
        echo "</tbody>";
        break;
    case 'health': case 'status': case 'risks':
        echo "<thead><tr><th>Iniciativa</th><th>Situação</th><th>Saúde</th><th>Atraso</th><th>Maior risco</th></tr></thead><tbody>";
        foreach ($rows as $r) {
            if ($detail === 'health' && !in_array($r['health'], ['yellow', 'red'], true)) {
                continue;
            }
            if ($detail === 'risks' && ($r['max_risk'] ?? 0) < (int) Settings::thresholds()['risk_yellow']) {
                continue;
            }
            echo "<tr><td><a href='" . $e(Menu::url('/front/initiative.php?id=' . $r['id'])) . "'>" . $e($r['code'] . ' · ' . $r['name']) . "</a></td><td>" . Initiative::statusBadge($r['status']) . "</td><td>" . Menu::healthBadge($r['health']) . "</td>"
                . "<td>" . ($r['delay'] === null ? 'sem dado' : $r['delay'] . ' d.ú.') . "</td><td>" . $e($r['max_risk'] ?? '—') . "</td></tr>";
        }
        echo "</tbody>";
        break;
    default:
        header('Location: ' . Menu::url('/front/inbox.php'));
        return;
}
echo "</table></div></div>";
Html::footer();
