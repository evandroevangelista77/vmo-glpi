<?php

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Benefit;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\Measurement;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Settings;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$id = (int) ($_GET['id'] ?? 0);
$e = fn ($v) => Menu::e($v);
$form = function (string $do, array $hidden, string $inner, string $btn = 'Salvar', string $cls = 'btn-primary btn-sm') use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'>$h$inner"
        . ($btn !== '' ? "<button class='btn $cls mt-2'>" . $e($btn) . "</button>" : '') . "</form>";
};
$field = fn (string $label, string $input, string $class = '') => "<div class='$class'><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";
$area = fn (string $name, $value = '', int $rows = 2) => "<textarea class='form-control' name='" . $e($name) . "' rows='$rows'>" . $e($value) . "</textarea>";

$b = $id ? Benefit::load($id) : null;
if (!$b && !Access::canCreate()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}
$canEdit = $b ? Benefit::canEdit($b) && $b['status'] !== 'closed' : true;
$self = '/front/benefit.php?id=' . $id;
$fromInitiative = (int) ($_GET['initiative'] ?? 0);

Menu::header($b ? $b['name'] : 'Novo benefício', 'benefits');
echo Menu::title($b ? $b['name'] : 'Novo benefício', $b ? 'Chave econômica: ' . $b['economic_key'] : 'Benefício financeiro exige moeda e horizonte para ser aprovado; o não financeiro exige rubrica e evidência.',
    Menu::button('Benefícios', Menu::url('/front/benefits.php'), 'ti-arrow-left', 'btn-outline-secondary'));

$dis = $canEdit ? '' : 'disabled';
$inner = "<fieldset $dis><div class='vmo-grid2'>" . $field('Nome', $input('name', $b['name'] ?? '', 'required'), 'required')
    . $field('Tipo', Menu::select('type', Benefit::TYPES, $b['type'] ?? 'financial'))
    . $field('Moeda (financeiro)', $input('currency', $b['currency'] ?? Settings::currency(), 'maxlength=3'))
    . $field('Unidade / rubrica', $input('unit', $b['unit'] ?? '', 'placeholder="ex.: horas, % de satisfação"'))
    . $field('Direção desejada', Menu::select('direction', Benefit::DIRECTIONS, $b['direction'] ?? 'increase'))
    . $field('Baseline', $input('baseline_value', $b['baseline_value'] ?? '', 'inputmode=decimal')) . $field('Meta', $input('target', $b['target'] ?? '', 'inputmode=decimal'))
    . $field('Início do horizonte', $input('horizon_start', $b['horizon_start'] ?? '', "type='date'")) . $field('Fim do horizonte', $input('horizon_end', $b['horizon_end'] ?? '', "type='date'"))
    . $field('Dono (declara medições)', Menu::userSelect('users_id_owner', (int) ($b['users_id_owner'] ?? 0))) . $field('Validador (valida medições)', Menu::userSelect('users_id_validator', (int) ($b['users_id_validator'] ?? 0)))
    . $field('Fonte dos dados', $input('source', $b['source'] ?? '')) . $field('Atribuição', $input('attribution_note', $b['attribution_note'] ?? '', 'placeholder="ex.: 60% atribuível à iniciativa"'))
    . "</div><div class='mt-2 vmo-grid2'>" . $field('Método de mensuração', $area('method', $b['method'] ?? '')) . $field('Rubrica (não financeiro)', $area('rubric', $b['rubric'] ?? '')) . $field('Descrição', $area('description', $b['description'] ?? '')) . '</div></fieldset>';
if (!$b && Access::canCreate()) {
    $inner .= $field('Entidade', Entity::dropdown(['name' => 'entities_id', 'value' => $_SESSION['glpiactive_entity'], 'display' => false]), 'mt-2');
}
echo "<div class='card mb-3'><div class='card-body'>" . $form('benefit_save', ['id' => $id, 'version' => $b['version'] ?? 0, 'return' => $b ? $self : '/front/benefits.php'], $inner, $canEdit ? 'Salvar' : '') . "</div></div>";

if (!$b) {
    echo "<div class='alert alert-info'>Depois de salvar, aloque o benefício a uma ou mais iniciativas e peça a aprovação.</div>";
    Html::footer();
    return;
}

// ---- aprovação
if ($b['status'] === 'draft') {
    $canApprove = Access::canApprove() && (int) $b['users_id_author'] !== (int) Session::getLoginUserID();
    echo "<div class='card mb-3'><div class='card-body d-flex align-items-center gap-3'><span>Benefício em <b>rascunho</b>: ainda não pode receber medições.</span>"
        . ($canApprove ? Menu::actionForm('benefit_approve', ['id' => $id, 'return' => $self], "<button class='btn btn-success'><i class='ti ti-check me-1'></i>Aprovar</button>")
            : "<span class='vmo-muted small'>A aprovação exige o direito de aprovar e outra pessoa que não quem cadastrou.</span>") . "</div></div>";
}

// ---- alocações
$allocs = Benefit::allocations($id);
$sum = array_sum(array_map(fn ($a) => (float) $a['percent'], $allocs));
echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Alocação entre iniciativas</h3><div class='ms-auto small vmo-muted'>Total alocado: " . $e(rtrim(rtrim(number_format($sum, 3, ',', ''), '0'), ',')) . "% (máximo 100%)</div></div>"
    . "<div class='table-responsive'><table class='table card-table'><tbody>";
foreach ($allocs as $a) {
    $i = Db::row('initiatives', (int) $a['initiatives_id']);
    $visible = $i && Access::canSeeInitiative($i);
    echo "<tr><td>" . ($visible ? "<a href='" . $e(Menu::url('/front/initiative.php?id=' . $i['id'])) . "'>" . $e($i['code'] . ' · ' . $i['name']) . "</a>" : "<span class='vmo-muted'>iniciativa restrita</span>")
        . "</td><td>" . $e(rtrim(rtrim($a['percent'], '0'), '.')) . "%</td><td>" . ($canEdit ? Menu::actionForm('benefit_unallocate', ['id' => $a['id'], 'return' => $self], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") : '') . "</td></tr>";
}
echo ($allocs ? '' : "<tr><td class='text-center text-secondary py-3'>Sem alocação.</td></tr>") . "</tbody></table></div>";
if ($canEdit && $sum < 100) {
    $opts = [];
    foreach (Access::visibleInitiatives(['entities_id' => (int) $b['entities_id']]) as $i) {
        if (!countElementsInTable(Db::t('allocations'), ['benefits_id' => $id, 'initiatives_id' => $i['id']])) {
            $opts[$i['id']] = $i['code'] . ' · ' . $i['name'];
        }
    }
    echo "<div class='card-body border-top'>" . $form('benefit_allocate', ['benefits_id' => $id, 'return' => $self], "<div class='vmo-grid2'>" . $field('Iniciativa', Menu::select('initiatives_id', $opts, $fromInitiative ?: '')) . $field('Percentual', $input('percent', (string) (100 - $sum), 'inputmode=decimal')) . '</div>', 'Alocar') . "</div>";
}
echo "</div>";

// ---- plano e medições
if ($b['status'] === 'approved' || $b['status'] === 'closed' || $allocs) {
    $plans = Benefit::plans($id);
    $meas = Measurement::forBenefit($id);
    $fin = Access::canFinance();
    echo "<div class='row'><div class='col-lg-5'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Previsto por período</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Mês</th><th class='text-end'>Previsto</th></tr></thead><tbody>";
    foreach ($plans as $p) {
        echo "<tr><td>" . $e(substr($p['period'], 0, 7)) . "</td><td class='text-end'>" . ($fin ? $e(Menu::money((float) $p['planned_amount'], (string) $b['currency'])) : '—') . "</td></tr>";
    }
    echo ($plans ? '' : "<tr><td colspan='2' class='text-center text-secondary py-3'>Sem previsto cadastrado.</td></tr>") . "</tbody></table></div>";
    if ($canEdit && $fin) {
        echo "<div class='card-body border-top'>" . $form('benefit_plan', ['benefits_id' => $id, 'return' => $self], "<div class='vmo-grid2'>" . $field('Mês (AAAA-MM)', $input('period', date('Y-m'), 'required')) . $field('Valor previsto', $input('planned_amount', '', 'inputmode=decimal required')) . '</div>', 'Salvar previsto') . "</div>";
    }
    echo "</div></div><div class='col-lg-7'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Medições</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Mês</th><th>Rev.</th><th class='text-end'>Declarado</th><th class='text-end'>Validado</th><th>Situação</th><th>Quem</th><th></th></tr></thead><tbody>";
    foreach ($meas as $m) {
        [$lbl, $col, $ico] = Measurement::STATUSES[$m['status']];
        echo "<tr class='" . ($m['is_current'] ? '' : 'opacity-50') . "'><td>" . $e(substr($m['period'], 0, 7)) . "</td><td>" . (int) $m['revision'] . ($m['is_current'] ? '' : ' <small>(substituída)</small>') . "</td>"
            . "<td class='text-end'>" . ($b['type'] === 'financial' ? ($fin ? $e(Menu::money((float) $m['declared_amount'], (string) $b['currency'])) : '—') : $e($m['observed_value'] . ' ' . $b['unit'])) . "</td>"
            . "<td class='text-end'>" . ($m['validated_amount'] !== null && $fin ? $e(Menu::money((float) $m['validated_amount'], (string) $b['currency'])) : '—') . "</td>"
            . "<td>" . Menu::badge($lbl, $col, $ico) . ($m['validation_comment'] ? "<div class='small vmo-muted'>" . $e($m['validation_comment']) . "</div>" : '') . "</td>"
            . "<td class='small'>" . $e(Db::userName((int) $m['users_id_author'])) . ($m['users_id_validator'] ? '<br>→ ' . $e(Db::userName((int) $m['users_id_validator'])) : '') . "<div class='vmo-muted'>" . $e(mb_strimwidth((string) $m['evidence'], 0, 60, '…')) . "</div></td><td>";
        if (Measurement::canValidate($b, $m)) {
            echo "<details class='vmo-inline'><summary>Validar</summary>" . $form('measure_decide', ['id' => $m['id'], 'return' => $self],
                $field('Valor validado (deixe em branco para aceitar o declarado)', $input('adjusted', '', 'inputmode=decimal')) . $field('Comentário (obrigatório ao rejeitar, devolver ou ajustar)', $area('comment'))
                . "<div class='d-flex gap-2 mt-2'><button class='btn btn-success btn-sm' name='decision' value='validated'>Validar</button><button class='btn btn-warning btn-sm' name='decision' value='returned'>Devolver</button><button class='btn btn-danger btn-sm' name='decision' value='rejected'>Rejeitar</button></div>", '') . "</details>";
        } elseif ($m['status'] === 'declared') {
            echo "<span class='small vmo-muted'>aguarda validador diferente do autor</span>";
        }
        echo "</td></tr>";
    }
    echo ($meas ? '' : "<tr><td colspan='7' class='text-center text-secondary py-3'>Sem medições.</td></tr>") . "</tbody></table></div>";
    if ($b['status'] === 'approved' && Access::canUpdate()) {
        $late = Benefit::overduePeriods($b);
        $inner = "<div class='vmo-grid2'>" . $field('Mês (AAAA-MM)', $input('period', $late[0] ? substr($late[0], 0, 7) : date('Y-m'), 'required'))
            . ($b['type'] === 'financial' ? $field('Valor realizado declarado', $input('declared_amount', '', 'inputmode=decimal required')) : $field('Valor observado (' . ($b['unit'] ?: 'unidade') . ')', $input('observed_value', '', 'inputmode=decimal required')))
            . $field('Evidência (link, documento ou descrição)', $input('evidence', '', 'required')) . $field('Comentário', $input('comment')) . '</div>';
        echo "<div class='card-body border-top'>" . ($late ? "<div class='alert alert-warning py-2'>Meses vencidos sem medição: " . $e(implode(', ', array_map(fn ($p) => substr($p, 0, 7), $late))) . "</div>" : '')
            . $form('measure_declare', ['benefits_id' => $id, 'return' => $self], $inner, 'Declarar medição') . "<div class='form-hint mt-2'>Retificar um mês já validado cria nova revisão e exige nova validação; as duas revisões nunca são somadas.</div></div>";
    }
    echo "</div></div></div>";
}
Html::footer();
