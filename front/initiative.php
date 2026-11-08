<?php

/**
 * Ficha da iniciativa: resumo/edição, tarefas, dependências, custos, benefícios, riscos e decisões,
 * baseline e mudanças, organização (departamentos, equipes, vínculos) e histórico.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Audit;
use GlpiPlugin\Vmo\Baseline;
use GlpiPlugin\Vmo\Benefit;
use GlpiPlugin\Vmo\Calc;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\ChangeRequest;
use GlpiPlugin\Vmo\Cost;
use GlpiPlugin\Vmo\Db;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Dependency;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\ItemLink;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Risk;
use GlpiPlugin\Vmo\Settings;
use GlpiPlugin\Vmo\Task;

Session::checkLoginUser();
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$id = (int) ($_GET['id'] ?? 0);
$e = fn ($v) => Menu::e($v);
$tabs = ['resumo' => 'Resumo', 'tasks' => 'Tarefas', 'deps' => 'Dependências', 'costs' => 'Custos', 'benefits' => 'Benefícios', 'risks' => 'Riscos e decisões',
    'baseline' => 'Baseline e mudanças', 'org' => 'Organização', 'history' => 'Histórico'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'resumo';

/** Formulário pequeno que envia para action.php. */
$form = function (string $do, array $hidden, string $inner, string $btn = 'Salvar', string $btnClass = 'btn-primary btn-sm') use ($e): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        $h .= "<input type='hidden' name='" . $e($k) . "' value='" . $e($v) . "'>";
    }
    return "<form method='post' action='" . $e(Menu::url('/front/action.php')) . "'>" . Menu::csrf() . "<input type='hidden' name='do' value='" . $e($do) . "'>$h$inner"
        . ($btn !== '' ? "<button class='btn $btnClass mt-2'>" . $e($btn) . "</button>" : '') . "</form>";
};
$field = fn (string $label, string $input, string $class = '') => "<div class='$class'><label class='form-label'>" . $e($label) . "</label>$input</div>";
$input = fn (string $name, $value = '', string $attrs = '') => "<input class='form-control' name='" . $e($name) . "' value='" . $e($value) . "' $attrs>";
$area = fn (string $name, $value = '', int $rows = 3) => "<textarea class='form-control' name='" . $e($name) . "' rows='$rows'>" . $e($value) . "</textarea>";

// ---------------------------------------------------------------- nova iniciativa
if ($id === 0) {
    if (!Access::canCreate()) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    Menu::header('Nova iniciativa', 'initiatives');
    echo Menu::title('Nova iniciativa', 'Um rascunho exige só título e entidade. Para submeter à análise são necessários gestor, patrocinador, objetivo e datas planejadas.',
        Menu::button('Voltar', Menu::url('/front/initiatives.php'), 'ti-arrow-left', 'btn-outline-secondary'));
    $inner = "<div class='vmo-grid2'>" . $field('Título', $input('name', '', 'required'), 'required')
        . $field('Código (opcional)', $input('code', '', 'placeholder="gerado automaticamente"'))
        . $field('Entidade', Entity::dropdown(['name' => 'entities_id', 'value' => $_SESSION['glpiactive_entity'], 'display' => false]))
        . $field('Moeda funcional', $input('currency', Settings::currency(), 'maxlength=3'))
        . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, 3))
        . $field('Confidencial', "<label class='form-check'><input class='form-check-input' type='checkbox' name='confidentiality' value='1'><span class='form-check-label'>Só gestor, patrocinador, participantes e administração veem</span></label>")
        . "</div><div class='mt-3'>" . $field('Descrição', $area('description')) . "</div>";
    echo "<div class='card'><div class='card-body'>" . $form('initiative_save', ['id' => 0], $inner, 'Criar rascunho', 'btn-primary') . "</div></div>";
    Html::footer();
    return;
}

// ---------------------------------------------------------------- carregamento
$init = Initiative::load($id);
$canEdit = Access::canEditInitiative($init);
$metrics = Initiative::metricsFor([$id => $init], null, Access::canFinance())[$id];
$cur = $init['currency'];
$self = '/front/initiative.php?id=' . $id;

Menu::header($init['code'] . ' · ' . $init['name'], 'initiatives');
$actions = Menu::button('Portfólio', Menu::url('/front/initiatives.php'), 'ti-arrow-left', 'btn-outline-secondary');
if (Access::canCreate()) {
    $actions .= Menu::actionForm('initiative_copy', ['id' => $id], "<button class='btn btn-outline-secondary'><i class='ti ti-copy me-1'></i>Copiar</button>");
}
echo Menu::title($init['code'] . ' · ' . $init['name'], 'Responsável: ' . Db::userName((int) $init['users_id_manager']) . ' · Patrocinador: ' . Db::userName((int) $init['users_id_sponsor'])
    . ' · Entidade: ' . Dropdown::getDropdownName('glpi_entities', (int) $init['entities_id']), $actions);

// ---- cartões
$k = fn (string $label, string $value, string $sub = '', string $color = '#6366f1') => "<div class='vmo-kpi' style='--c:$color'><div class='vmo-kpi-label'>" . $e($label) . "</div><div class='vmo-kpi-value'>$value</div><div class='vmo-kpi-sub'>$sub</div></div>";
echo "<div class='vmo-kpis'>"
    . $k('Situação', Initiative::statusBadge($init['status']), $init['review_date'] ? 'revisão em ' . $e(Menu::date($init['review_date'])) : '')
    . $k('Saúde', Menu::healthBadge($metrics['health']), $metrics['stale'] ? 'atualização vencida' : ($metrics['max_risk'] ? 'maior risco: ' . $metrics['max_risk'] : ''), Menu::HEALTH[$metrics['health']][1])
    . $k('Progresso', Menu::pct($metrics['progress']), $init['progress_method'] === 'manual' ? 'método manual' : $metrics['tasks'] . ' tarefa(s)')
    . $k('Atraso', $metrics['delay'] === null ? "<span class='vmo-muted'>sem baseline</span>" : $metrics['delay'] . ' d.ú.', 'previsão ' . $e(Menu::date($init['forecast_end'] ?: $init['planned_end'])), '#f59e0b');
if (Access::canFinance()) {
    $var = $metrics['cost_variance'];
    echo $k('Custo real', Menu::money($metrics['actual'], $cur), $var ? 'variação ' . Menu::money($var[0], $cur) . ($var[1] !== null ? ' (' . number_format($var[1], 1, ',', '.') . '%)' : '') : 'sem baseline de custo', '#0ea5e9')
        . $k('Realização de valor', Menu::pct($metrics['realization'], 'n/a'), 'validado ' . Menu::money($metrics['value']['validated'][$cur] ?? null, $cur), '#22c55e')
        . $k('ROI', Menu::pct($metrics['roi'], 'n/a'), 'benefício validado ÷ custo real', '#a855f7');
}
echo "</div>";

echo "<div class='vmo-tabs'>";
foreach ($tabs as $key => $label) {
    echo "<a class='" . ($tab === $key ? 'active' : '') . "' href='" . $e(Menu::url($self . '&tab=' . $key)) . "'>" . $e($label) . "</a>";
}
echo "</div>";

// ================================================================ RESUMO
if ($tab === 'resumo') {
    $dis = $canEdit && !in_array($init['status'], ['closed', 'cancelled'], true) ? '' : 'disabled';
    $locked = (int) $init['baseline_revision'] > 0 ? 'readonly' : '';
    $inner = "<fieldset $dis><div class='vmo-grid2'>"
        . $field('Título', $input('name', $init['name'], 'required'), 'required')
        . $field('Gestor', Menu::userSelect('users_id_manager', (int) $init['users_id_manager']))
        . $field('Patrocinador', Menu::userSelect('users_id_sponsor', (int) $init['users_id_sponsor']))
        . $field('Objetivo estratégico', Menu::select('catalogs_id_objective', Catalog::options('objective'), $init['catalogs_id_objective'], '', true))
        . $field('Categoria', Menu::select('catalogs_id_category', Catalog::options('category'), $init['catalogs_id_category'], '', true))
        . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, $init['priority']))
        . $field('Início planejado' . ($locked ? ' (baseline)' : ''), $input('planned_start', $init['planned_start'], "type='date' $locked"))
        . $field('Término planejado' . ($locked ? ' (baseline)' : ''), $input('planned_end', $init['planned_end'], "type='date' $locked"))
        . $field('Previsão de término', $input('forecast_end', $init['forecast_end'], "type='date'"))
        . $field('Início real', $input('actual_start', $init['actual_start'], "type='date'"))
        . $field('Término real', $input('actual_end', $init['actual_end'], "type='date'"))
        . $field('Método de progresso', Menu::select('progress_method', ['auto' => 'Automático (tarefas)', 'manual' => 'Manual (justificado)'], $init['progress_method']))
        . $field('Progresso manual (%)', $input('progress_manual', $init['progress_manual'], 'inputmode=decimal'))
        . $field('Justificativa do progresso manual', $input('progress_justification', $init['progress_justification']))
        . $field('Confidencial', "<label class='form-check'><input class='form-check-input' type='checkbox' name='confidentiality' value='1'" . ($init['confidentiality'] ? ' checked' : '') . "><span class='form-check-label'>Visível só a gestor, patrocinador, participantes e administração</span></label>")
        . "</div><div class='mt-3 vmo-grid2'>"
        . $field('Descrição / escopo', $area('description', $init['description'], 4)) . $field('Problema', $area('problem', $init['problem'], 4))
        . $field('Objetivo', $area('objective', $init['objective'], 4)) . $field('Justificativa', $area('justification', $init['justification'], 4))
        . $field('Caso de negócio (benefício esperado, custo estimado, alternativas)', $area('business_case', $init['business_case'], 4), '')
        . "</div></fieldset>";
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Dados da iniciativa</h3><div class='ms-auto text-secondary small'>versão " . (int) $init['version'] . " · atualizada " . $e(Menu::datetime($init['date_mod'])) . "</div></div><div class='card-body'>"
        . ($dis ? "<div class='alert alert-info py-2'>Somente leitura: você não edita esta iniciativa ou ela está " . $e(mb_strtolower(Initiative::STATUSES[$init['status']][0])) . ".</div>" : '')
        . $form('initiative_save', ['id' => $id, 'version' => $init['version'], 'return' => $self], $inner, $dis ? '' : 'Salvar alterações')
        . "</div></div>";

    // mudança de situação
    $allowed = array_values(array_filter(Initiative::TRANSITIONS[$init['status']], fn ($to) => Initiative::canTransition($init, $to)));
    if ($allowed) {
        $labels = ['review' => 'Submeter à análise', 'approved' => 'Aprovar (congela a baseline)', 'draft' => $init['status'] === 'review' ? 'Devolver ao autor' : 'Reabrir como rascunho', 'rejected' => 'Rejeitar',
            'running' => $init['status'] === 'closed' ? 'Reabrir (exige nova versão do plano)' : ($init['status'] === 'suspended' ? 'Retomar' : 'Iniciar execução'), 'suspended' => 'Suspender',
            'delivered' => 'Registrar entrega (aceite do patrocinador)', 'measuring' => 'Iniciar mensuração de benefícios', 'closed' => 'Encerrar', 'cancelled' => 'Cancelar'];
        $opts = [];
        foreach ($allowed as $to) {
            $opts[$to] = $labels[$to] ?? Initiative::STATUSES[$to][0];
        }
        $inner = "<div class='vmo-grid2'>" . $field('Ação', Menu::select('to', $opts, array_key_first($opts)))
            . $field('Justificativa (obrigatória para devolver, rejeitar, suspender, cancelar, reabrir e dispensar horizonte)', $area('comment', '', 2))
            . $field('Data de revisão (suspensão)', $input('review_date', '', "type='date'")) . $field('Nova previsão de término (retomada)', $input('forecast_end', '', "type='date'"))
            . "</div><div class='mt-2 d-flex flex-wrap gap-3'>"
            . "<label class='form-check'><input class='form-check-input' type='checkbox' name='cancel_tasks' value='1'><span class='form-check-label'>Cancelar as tarefas pendentes (ao cancelar)</span></label>"
            . "<label class='form-check'><input class='form-check-input' type='checkbox' name='exception' value='1'><span class='form-check-label'>Exceção: entregar com tarefas obrigatórias abertas (exige direito de aprovar)</span></label></div>";
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Mudar situação</h3></div><div class='card-body'>"
            . $form('transition', ['id' => $id, 'return' => $self], $inner, 'Confirmar', 'btn-primary') . "</div></div>";
    } elseif ($init['status'] === 'review') {
        echo "<div class='alert alert-info'>Aguardando decisão do patrocinador/VMO. Quem submeteu a proposta não pode decidir sobre ela.</div>";
    }
    if (!empty($init['status_reason'])) {
        echo "<div class='card mb-3'><div class='card-body'><b>Última justificativa:</b> " . nl2br($e($init['status_reason'])) . "</div></div>";
    }

    // qualidade dos dados
    echo "<div class='card'><div class='card-body'><b>Qualidade dos dados:</b> completude " . Menu::pct($metrics['completeness'], 'n/a') . ($metrics['stale'] ? " · <span class='text-warning'>atualização vencida (cadência de " . Settings::cadenceDays() . " dias)</span>" : '') . "</div></div>";
}

// ================================================================ TAREFAS
if ($tab === 'tasks') {
    $tasks = Task::forInitiative($id);
    $roll = Calc::rollup($tasks);
    $parentsWithKids = array_flip(array_filter(array_map(fn ($t) => (int) $t['parent_id'], $tasks)));
    $taskOpts = ['0' => '— nenhuma (raiz) —'];
    foreach (Task::tree($tasks) as [$tid, $lvl]) {
        $taskOpts[$tid] = str_repeat('— ', $lvl) . $tasks[$tid]['name'];
    }
    $taskForm = function (array $t = []) use ($field, $input, $area, $taskOpts, $form, $id, $self) {
        $tid = (int) ($t['id'] ?? 0);
        $opts = $taskOpts;
        unset($opts[$tid]);
        $inner = "<div class='vmo-grid2'>" . $field('Título', $input('name', $t['name'] ?? '', 'required'), 'required')
            . $field('Tarefa-pai', Menu::select('parent_id', $opts, $t['parent_id'] ?? 0))
            . $field('Responsável', Menu::userSelect('users_id_responsible', (int) ($t['users_id_responsible'] ?? 0)))
            . $field('Início', $input('planned_start', $t['planned_start'] ?? '', "type='date'")) . $field('Término', $input('planned_end', $t['planned_end'] ?? '', "type='date'"))
            . $field('Esforço planejado (h)', $input('effort_plan', $t['effort_plan'] ?? '0', 'inputmode=decimal')) . $field('Peso', $input('weight', $t ? $t['weight'] : '1', 'inputmode=decimal'))
            . $field('Prioridade', Menu::select('priority', Initiative::PRIORITIES, $t['priority'] ?? 3))
            . $field('Tipo', "<label class='form-check'><input class='form-check-input' type='checkbox' name='is_milestone' value='1'" . (!empty($t['is_milestone']) ? ' checked' : '') . "><span class='form-check-label'>Marco (peso zero por padrão)</span></label>")
            . $field('Obrigatória para a entrega', Menu::select('is_required', ['1' => 'Sim', '0' => 'Não'], ($t['is_required'] ?? 1) ? '1' : '0'))
            . "</div><div class='mt-2'>" . $field('Descrição', $area('description', $t['description'] ?? '', 2))
            . $field('Checklist (um item por linha)', $area('checklist', $t && $t['checklist'] ? implode("\n", json_decode($t['checklist'], true) ?: []) : '', 2)) . '</div>';
        return $form('task_save', ['initiatives_id' => $id, 'id' => $tid, 'version' => $t['version'] ?? 0, 'return' => $self . '&tab=tasks'], $inner, $tid ? 'Salvar tarefa' : 'Adicionar tarefa');
    };

    echo "<div class='card mb-3'><div class='table-responsive'><table class='table table-vcenter card-table'><thead><tr><th>Tarefa</th><th>Situação</th><th>Responsável</th><th>Prazo</th><th>Peso</th><th style='min-width:130px'>Progresso</th><th>Ações</th></tr></thead><tbody>";
    foreach (Task::tree($tasks) as [$tid, $lvl]) {
        $t = $tasks[$tid];
        $summary = isset($parentsWithKids[$tid]);
        $prog = $summary ? ($roll[$tid] ?? null) : (float) $t['progress'];
        $late = in_array($t['status'], ['todo', 'doing'], true) && $t['planned_end'] && $t['planned_end'] < Db::today();
        echo "<tr class='" . ($t['status'] === 'cancelled' ? 'opacity-50' : '') . "'><td class='vmo-tree-" . min(4, $lvl) . "'>" . ($t['is_milestone'] ? "<i class='ti ti-flag text-warning me-1' title='Marco'></i>" : ($summary ? "<i class='ti ti-folder me-1 text-secondary'></i>" : ''))
            . "<b>" . $e($t['name']) . "</b>" . ($t['is_required'] ? '' : " <span class='badge bg-secondary-lt'>opcional</span>") . "</td>"
            . "<td>" . Menu::badge(Task::STATUSES[$t['status']][0], Task::STATUSES[$t['status']][1], Task::STATUSES[$t['status']][2]) . ($late ? " <span class='badge bg-danger-lt'>atrasada</span>" : '') . "</td>"
            . "<td>" . $e(Db::userName((int) $t['users_id_responsible'])) . "</td><td>" . $e(Menu::date($t['planned_end'])) . "</td><td>" . $e(rtrim(rtrim($t['weight'], '0'), '.')) . "</td>"
            . "<td>" . ($prog === null ? "<span class='vmo-muted'>sem dado</span>" : "<div class='vmo-bar'><span style='width:" . min(100, $prog) . "%'></span></div><small>" . number_format($prog, 0) . "%" . ($summary ? ' (derivado)' : '') . "</small>") . "</td><td class='text-nowrap'>";
        if (!$summary && $t['status'] !== 'cancelled' && Access::canUpdate()) {
            echo "<details class='vmo-inline'><summary>Registrar progresso</summary>"
                . $form('task_progress', ['id' => $tid, 'version' => $t['version'], 'return' => $self . '&tab=tasks'],
                    $field('Percentual (0–100)', $input('progress', $t['progress'], 'inputmode=decimal required')) . $field('Esforço realizado nesta atualização (h)', $input('effort_delta', '0', 'inputmode=decimal'))
                    . $field('Data de referência', $input('reference_date', Db::today(), "type='date'")) . $field('Evidência (link ou referência)', $input('evidence')) . $field('Comentário', $area('comment', '', 2)), 'Registrar')
                . "</details>";
        }
        if ($canEdit) {
            if ($t['status'] === 'todo' && !$summary) {
                echo Menu::actionForm('task_start', ['id' => $tid, 'return' => $self . '&tab=tasks'], "<button class='btn btn-sm btn-outline-primary mt-1'>Iniciar</button>");
            }
            echo "<details class='vmo-inline'><summary>Editar</summary>" . $taskForm($t) . "</details>";
            echo Menu::actionForm($t['status'] === 'cancelled' ? 'task_reactivate' : 'task_cancel', ['initiatives_id' => $id, 'id' => $tid, 'return' => $self . '&tab=tasks'],
                "<button class='btn btn-sm btn-outline-secondary mt-1'>" . ($t['status'] === 'cancelled' ? 'Reativar' : 'Cancelar') . "</button>");
            echo Menu::actionForm('task_delete', ['initiatives_id' => $id, 'id' => $tid, 'return' => $self . '&tab=tasks'], "<button class='btn btn-sm btn-outline-danger mt-1' onclick=\"return confirm('Remover a tarefa?')\">Remover</button>");
        }
        echo "</td></tr>";
    }
    if (!$tasks) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Sem tarefas: o progresso aparece como \"sem dado\" (nunca 0%).</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit) {
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Nova tarefa</h3></div><div class='card-body'>" . $taskForm() . "</div></div>";
    }
}

// ================================================================ DEPENDÊNCIAS
if ($tab === 'deps') {
    $tasks = Task::forInitiative($id);
    $deps = Dependency::forInitiative($id);
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Tipo</th><th>Predecessora</th><th></th><th>Sucessora</th><th>Defasagem</th><th>Vínculo</th><th></th></tr></thead><tbody>";
    foreach ($deps as $d) {
        echo "<tr><td>" . ($d['kind'] === 'task' ? 'Tarefa' : 'Iniciativa') . "</td><td>" . $e($d['pred_name']) . "</td><td>→</td><td>" . $e($d['succ_name']) . "</td><td>" . (int) $d['lag_days'] . " d.ú.</td>"
            . "<td>" . ($d['is_hard'] ? 'Rígido' : 'Informativo') . (!empty($d['exception_reason']) ? " <span class='badge bg-warning-lt' title='" . $e($d['exception_reason']) . "'>com exceção</span>" : '') . "</td><td class='text-nowrap'>";
        if ($canEdit) {
            echo Menu::actionForm('dep_remove', ['id' => $d['id'], 'return' => $self . '&tab=deps'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>");
            if ($d['is_hard'] && Access::canApprove() && empty($d['exception_reason'])) {
                echo "<details class='vmo-inline'><summary>Exceção</summary>" . $form('dep_exception', ['id' => $d['id'], 'return' => $self . '&tab=deps'], $field('Motivo', $input('reason', '', 'required')), 'Liberar') . "</details>";
            }
        }
        echo "</td></tr>";
    }
    if (!$deps) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Sem dependências.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit) {
        $topts = [];
        foreach ($tasks as $t) {
            $topts[$t['id']] = $t['name'];
        }
        $iopts = [];
        foreach (Access::visibleInitiatives() as $i) {
            if ((int) $i['id'] !== $id) {
                $iopts[$i['id']] = $i['code'] . ' · ' . $i['name'];
            }
        }
        $common = $field('Defasagem (dias úteis)', $input('lag_days', '0', 'type=number')) . $field('Vínculo', Menu::select('is_hard', ['1' => 'Rígido (bloqueia o início)', '0' => 'Informativo'], '1'));
        echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Entre tarefas desta iniciativa (término → início)</h3></div><div class='card-body'>"
            . $form('dep_add', ['kind' => 'task', 'return' => $self . '&tab=deps'], "<div class='vmo-grid2'>" . $field('Predecessora', Menu::select('pred_id', $topts, '')) . $field('Sucessora', Menu::select('succ_id', $topts, '')) . $common . '</div>', 'Criar vínculo')
            . "</div></div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Entre iniciativas (esta iniciativa depende de…)</h3></div><div class='card-body'>"
            . $form('dep_add', ['kind' => 'initiative', 'succ_id' => $id, 'return' => $self . '&tab=deps'], "<div class='vmo-grid2'>" . $field('Iniciativa predecessora', Menu::select('pred_id', $iopts, '')) . $common . '</div>', 'Criar vínculo')
            . "</div></div></div></div>";
    }
}

// ================================================================ CUSTOS
if ($tab === 'costs') {
    if (!Access::canFinance()) {
        echo "<div class='alert alert-warning'>Os valores financeiros exigem o direito financeiro do VMO no seu perfil.</div>";
    } else {
        $totals = Cost::totals($id);
        $b = Baseline::latest($id);
        $budget = $b ? (json_decode((string) $b['budget_json'], true) ?: []) : [];
        echo "<div class='vmo-kpis'>";
        foreach ($totals + array_fill_keys(array_keys($budget), ['forecast' => 0, 'actual' => 0, 'capex' => 0, 'opex' => 0]) as $c => $t) {
            $bud = $budget[$c] ?? null;
            $var = $bud !== null ? Calc::costVariance($t['forecast'], (float) $bud) : null;
            echo $k("Orçamento da baseline ($c)", $bud === null ? "<span class='vmo-muted'>sem baseline</span>" : Menu::money((float) $bud, $c), 'congelado na aprovação', '#64748b')
                . $k("Previsão atual ($c)", Menu::money($t['forecast'], $c), $var ? 'variação ' . Menu::money($var[0], $c) . ($var[1] !== null ? ' (' . number_format($var[1], 1, ',', '.') . '%)' : '') : '', '#0ea5e9')
                . $k("Real ($c)", Menu::money($t['actual'], $c), 'CAPEX ' . Menu::money($t['capex'], $c) . ' · OPEX ' . Menu::money($t['opex'], $c), '#22c55e')
                . $k("Saldo da previsão ($c)", Menu::money($t['forecast'] - $t['actual'], $c), 'previsão − real', '#f59e0b');
        }
        echo "</div><div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Competência</th><th>Tipo</th><th>Categoria</th><th>Descrição</th><th class='text-end'>Valor</th><th>Origem</th><th></th></tr></thead><tbody>";
        foreach (Cost::forInitiative($id) as $c) {
            $neg = (float) $c['amount'] < 0;
            echo "<tr><td>" . $e(Menu::date($c['competence_date'])) . "</td><td>" . $e(Cost::KINDS[$c['kind']]) . ' · ' . $e(Cost::TYPES[$c['cost_type']]) . "</td><td>" . $e($c['category']) . "</td><td>" . $e($c['description']) . "</td>"
                . "<td class='text-end " . ($neg ? 'vmo-money-neg' : '') . "'>" . $e(Menu::money((float) $c['amount'], $c['currency'])) . "</td><td>" . $e($c['source']) . ($c['external_key'] ? ' · ' . $e($c['external_key']) : '') . "</td><td>";
            if ($c['kind'] === 'actual' && !$neg && !(int) $c['reversal_of_id'] && $canEdit && !countElementsInTable(Db::t('costs'), ['reversal_of_id' => (int) $c['id']])) {
                echo "<details class='vmo-inline'><summary>Estornar</summary>" . $form('cost_reverse', ['id' => $c['id'], 'return' => $self . '&tab=costs'], $field('Motivo do estorno', $input('reason', '', 'required')), 'Estornar') . "</details>";
            }
            echo "</td></tr>";
        }
        echo "</tbody></table></div></div>";
        if ($canEdit) {
            $inner = "<div class='vmo-grid2'>" . $field('Tipo', Menu::select('kind', Cost::KINDS, 'forecast')) . $field('Classificação', Menu::select('cost_type', Cost::TYPES, 'opex'))
                . $field('Competência', $input('competence_date', Db::today(), "type='date' required")) . $field('Valor', $input('amount', '', 'inputmode=decimal required placeholder="0,00"'))
                . $field('Moeda', $input('currency', $cur, 'maxlength=3')) . $field('Categoria', $input('category')) . $field('Descrição', $input('description'))
                . $field('Origem', $input('source', 'manual')) . $field('Chave externa (evita duplicar)', $input('external_key')) . $field('Evidência', $input('evidence')) . '</div>';
            echo "<div class='card'><div class='card-header'><h3 class='card-title'>Novo lançamento</h3></div><div class='card-body'>" . $form('cost_add', ['initiatives_id' => $id, 'return' => $self . '&tab=costs'], $inner, 'Lançar') . "</div></div>";
        }
    }
}

// ================================================================ BENEFÍCIOS
if ($tab === 'benefits') {
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Benefício</th><th>Tipo</th><th>Situação</th><th>Atribuição</th>" . (Access::canFinance() ? '<th class="text-end">Previsto</th><th class="text-end">Validado</th>' : '') . "<th></th></tr></thead><tbody>";
    $has = false;
    foreach (Db::rows('allocations', ['initiatives_id' => $id]) as $a) {
        $b = Db::row('benefits', (int) $a['benefits_id']);
        if (!$b || $b['is_deleted']) {
            continue;
        }
        $has = true;
        $val = Benefit::valueByInitiative([$id], Db::today())[$id] ?? ['planned' => [], 'validated' => []];
        echo "<tr><td><a href='" . $e(Menu::url('/front/benefit.php?id=' . $b['id'])) . "'>" . $e($b['name']) . "</a></td><td>" . $e(Benefit::TYPES[$b['type']]) . "</td>"
            . "<td>" . Menu::badge(Benefit::STATUSES[$b['status']][0], Benefit::STATUSES[$b['status']][1]) . "</td><td>" . $e(rtrim(rtrim($a['percent'], '0'), '.')) . "%</td>";
        if (Access::canFinance()) {
            echo "<td class='text-end'>" . ($b['type'] === 'financial' ? $e(Menu::money($val['planned'][$b['currency']] ?? null, (string) $b['currency'])) : '—') . "</td><td class='text-end'>" . ($b['type'] === 'financial' ? $e(Menu::money($val['validated'][$b['currency']] ?? null, (string) $b['currency'])) : '—') . "</td>";
        }
        echo "<td>" . ($canEdit ? Menu::actionForm('benefit_unallocate', ['id' => $a['id'], 'return' => $self . '&tab=benefits'], "<button class='btn btn-sm btn-outline-danger'>Remover alocação</button>") : '') . "</td></tr>";
    }
    if (!$has) {
        echo "<tr><td colspan='7' class='text-center text-secondary py-4'>Nenhum benefício alocado a esta iniciativa.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit) {
        $opts = [];
        foreach (Benefit::visible() as $b) {
            if ((int) $b['entities_id'] === (int) $init['entities_id'] && !countElementsInTable(Db::t('allocations'), ['benefits_id' => $b['id'], 'initiatives_id' => $id])) {
                $opts[$b['id']] = $b['name'];
            }
        }
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Alocar benefício existente</h3><div class='ms-auto'>" . Menu::button('Novo benefício', Menu::url('/front/benefit.php?id=0&initiative=' . $id), 'ti-plus', 'btn-outline-primary btn-sm') . "</div></div><div class='card-body'>"
            . $form('benefit_allocate', ['initiatives_id' => $id, 'return' => $self . '&tab=benefits'], "<div class='vmo-grid2'>" . $field('Benefício', Menu::select('benefits_id', $opts, '')) . $field('Percentual atribuído a esta iniciativa', $input('percent', '100', 'inputmode=decimal')) . '</div>', 'Alocar')
            . "<div class='form-hint mt-2'>A soma das alocações de um benefício nunca passa de 100%. O portfólio soma o benefício uma só vez.</div></div></div>";
    }
}

// ================================================================ RISCOS E DECISÕES
if ($tab === 'risks') {
    $risks = Risk::forInitiative($id);
    echo "<div class='card mb-3'><div class='table-responsive'><table class='table card-table'><thead><tr><th>Tipo</th><th>Título</th><th>Prob.</th><th>Impacto</th><th>Exposição</th><th>Responsável</th><th>Prazo</th><th>Situação</th><th></th></tr></thead><tbody>";
    $riskForm = function (array $r = []) use ($form, $field, $input, $area, $id, $self) {
        $inner = "<div class='vmo-grid2'>" . $field('Tipo', Menu::select('kind', Risk::KINDS, $r['kind'] ?? 'risk')) . $field('Título', $input('title', $r['title'] ?? '', 'required'))
            . $field('Probabilidade (1–5)', Menu::select('probability', Risk::SCALE, $r['probability'] ?? 3)) . $field('Impacto (1–5)', Menu::select('impact', Risk::SCALE, $r['impact'] ?? 3))
            . $field('Responsável', Menu::userSelect('users_id_owner', (int) ($r['users_id_owner'] ?? 0))) . $field('Prazo da resposta', $input('due_date', $r['due_date'] ?? '', "type='date'"))
            . $field('Situação', Menu::select('status', Risk::STATUSES, $r['status'] ?? 'open')) . '</div><div class="mt-2 vmo-grid2">'
            . $field('Causa', $area('cause', $r['cause'] ?? '', 2)) . $field('Impacto (descrição)', $area('impact_text', $r['impact_text'] ?? '', 2))
            . $field('Resposta', $area('response', $r['response'] ?? '', 2)) . $field('Resultado da resposta (obrigatório para encerrar)', $area('outcome', $r['outcome'] ?? '', 2)) . '</div>';
        return $form('risk_save', ['initiatives_id' => $id, 'id' => $r['id'] ?? 0, 'version' => $r['version'] ?? 1, 'return' => $self . '&tab=risks'], $inner, ($r['id'] ?? 0) ? 'Salvar' : 'Registrar');
    };
    foreach ($risks as $r) {
        $x = Calc::riskExposure((int) $r['probability'], (int) $r['impact']);
        echo "<tr><td>" . $e(Risk::KINDS[$r['kind']]) . "</td><td>" . $e($r['title']) . "</td><td>" . (int) $r['probability'] . "</td><td>" . (int) $r['impact'] . "</td>"
            . "<td>" . Menu::badge((string) $x, Risk::exposureColor($x)) . "</td><td>" . $e(Db::userName((int) $r['users_id_owner'])) . "</td><td>" . $e(Menu::date($r['due_date'])) . "</td><td>" . $e(Risk::STATUSES[$r['status']]) . "</td>"
            . "<td>" . (Access::canUpdate() ? "<details class='vmo-inline'><summary>Editar</summary>" . $riskForm($r) . "</details>" : '') . "</td></tr>";
    }
    if (!$risks) {
        echo "<tr><td colspan='9' class='text-center text-secondary py-4'>Nenhum risco ou problema registrado.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if (Access::canUpdate()) {
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Novo risco ou problema</h3></div><div class='card-body'>" . $riskForm() . "</div></div>";
    }
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Decisões do comitê</h3></div><div class='card-body'>";
    foreach (Risk::decisions($id) as $d) {
        echo "<div class='mb-2'><b>" . $e($d['title']) . "</b> <span class='vmo-muted small'>" . $e(Menu::datetime($d['decided_at'])) . " · " . $e(Db::userName((int) $d['users_id'])) . "</span><div>" . nl2br($e($d['decision_text'])) . "</div></div>";
    }
    if (Access::canUpdate()) {
        echo $form('decision_add', ['initiatives_id' => $id, 'return' => $self . '&tab=risks'], "<div class='vmo-grid2'>" . $field('Decisão', $input('title', '', 'required')) . $field('Detalhes', $area('decision_text', '', 2)) . '</div>', 'Registrar decisão');
    }
    echo "</div></div>";
}

// ================================================================ BASELINE E MUDANÇAS
if ($tab === 'baseline') {
    $bls = Baseline::all($id);
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Revisões da baseline (imutáveis)</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Revisão</th><th>Aprovada em</th><th>Aprovador</th><th>Início</th><th>Término</th>" . (Access::canFinance() ? '<th>Orçamento</th>' : '') . "<th>Fórmula / limites</th><th>Integridade</th><th>Motivo</th></tr></thead><tbody>";
    foreach ($bls as $b) {
        $bud = json_decode((string) $b['budget_json'], true) ?: [];
        echo "<tr><td>v" . (int) $b['revision'] . "</td><td>" . $e(Menu::datetime($b['approved_at'])) . "</td><td>" . $e(Db::userName((int) $b['users_id_approver'])) . "</td><td>" . $e(Menu::date($b['planned_start'])) . "</td><td>" . $e(Menu::date($b['planned_end'])) . "</td>";
        if (Access::canFinance()) {
            echo "<td>" . $e(implode(' | ', array_map(fn ($c, $v) => Menu::money((float) $v, $c), array_keys($bud), $bud)) ?: '—') . "</td>";
        }
        echo "<td>v" . $e($b['formula_version']) . " / v" . (int) $b['thresholds_version'] . "</td><td>" . (Baseline::verify($b) ? "<span class='text-success'><i class='ti ti-shield-check'></i> íntegra</span>" : "<span class='text-danger'>alterada</span>") . "</td><td>" . $e($b['reason']) . "</td></tr>";
    }
    if (!$bls) {
        echo "<tr><td colspan='9' class='text-center text-secondary py-4'>A baseline é congelada quando a proposta é aprovada.</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if (count($bls) >= 2) {
        $diff = Baseline::compare($bls[count($bls) - 2], $bls[count($bls) - 1]);
        echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Comparação v" . (int) $bls[count($bls) - 2]['revision'] . " → v" . (int) $bls[count($bls) - 1]['revision'] . "</h3></div><div class='card-body'>";
        foreach ($diff as $label => [$a, $b]) {
            echo "<div><b>" . $e($label) . ":</b> " . $e(mb_strimwidth((string) $a, 0, 120, '…')) . " → " . $e(mb_strimwidth((string) $b, 0, 120, '…')) . "</div>";
        }
        echo ($diff ? '' : 'Sem diferenças em prazo, escopo e orçamento.') . "</div></div>";
    }
    echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>Solicitações de mudança</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Tipo</th><th>Proposta</th><th>Justificativa</th><th>Solicitante</th><th>Situação</th><th></th></tr></thead><tbody>";
    foreach (ChangeRequest::forInitiative($id) as $c) {
        $pr = json_decode((string) $c['proposed'], true) ?: [];
        $txt = [];
        foreach (['planned_start' => 'Início', 'planned_end' => 'Término'] as $f => $l) {
            if (isset($pr[$f])) {
                $txt[] = "$l: " . Menu::date($pr[$f]);
            }
        }
        if (isset($pr['budget']) && Access::canFinance()) {
            $txt[] = 'Orçamento: ' . implode(' | ', array_map(fn ($cc, $v) => Menu::money((float) $v, $cc), array_keys($pr['budget']), $pr['budget']));
        }
        if (isset($pr['scope'])) {
            $txt[] = 'Escopo: ' . mb_strimwidth($pr['scope'], 0, 80, '…');
        }
        if (!empty($pr['reopen'])) {
            $txt[] = 'Reabertura da iniciativa';
        }
        echo "<tr><td>" . $e(ChangeRequest::TYPES[$c['type']] ?? $c['type']) . "</td><td>" . $e(implode('; ', $txt)) . "</td><td>" . $e($c['justification']) . "</td><td>" . $e(Db::userName((int) $c['users_id_requester'])) . "</td>"
            . "<td>" . $e(['pending' => 'Pendente', 'approved' => 'Aprovada', 'rejected' => 'Rejeitada'][$c['status']]) . ($c['decision_comment'] ? "<div class='small vmo-muted'>" . $e($c['decision_comment']) . "</div>" : '') . "</td><td>";
        if (ChangeRequest::canDecide($init, $c)) {
            echo "<details class='vmo-inline'><summary>Decidir</summary>" . $form('change_decide', ['id' => $c['id'], 'return' => $self . '&tab=baseline'], $field('Comentário (obrigatório ao rejeitar)', $area('comment', '', 2))
                . "<div class='d-flex gap-2 mt-2'><button class='btn btn-success btn-sm' name='decision' value='approve'>Aprovar</button><button class='btn btn-danger btn-sm' name='decision' value='reject'>Rejeitar</button></div>", '') . "</details>";
        } elseif ($c['status'] === 'pending') {
            echo "<span class='vmo-muted small'>aguarda aprovador diferente do solicitante</span>";
        }
        echo "</td></tr>";
    }
    echo "</tbody></table></div></div>";
    if ($canEdit && (int) $init['baseline_revision'] > 0) {
        $inner = "<div class='vmo-grid2'>" . $field('Tipo', Menu::select('type', ChangeRequest::TYPES, 'schedule')) . $field('Novo início planejado', $input('planned_start', '', "type='date'")) . $field('Novo término planejado', $input('planned_end', '', "type='date'"))
            . (Access::canFinance() ? $field("Novo orçamento ($cur)", $input('budget', '', 'inputmode=decimal')) : '') . "</div><div class='mt-2'>" . $field('Novo escopo', $area('scope', '', 2)) . $field('Justificativa (obrigatória)', $area('justification', '', 2)) . '</div>';
        echo "<div class='card'><div class='card-header'><h3 class='card-title'>Solicitar mudança de baseline</h3></div><div class='card-body'>" . $form('change_open', ['initiatives_id' => $id, 'return' => $self . '&tab=baseline'], $inner, 'Enviar solicitação') . "</div></div>";
    }
}

// ================================================================ ORGANIZAÇÃO
if ($tab === 'org') {
    $depts = Department::forInitiative($id);
    echo "<div class='row'><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Departamentos</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Departamento</th><th>Papel</th><th>Participação</th><th></th></tr></thead><tbody>";
    foreach ($depts as $d) {
        echo "<tr><td>" . $e($d['dept_code'] . ' · ' . $d['dept_name']) . ($d['dept_active'] ? '' : " <span class='badge bg-secondary-lt'>inativo</span>") . "</td><td>" . $e(Department::ROLES[$d['role']] ?? $d['role']) . "</td><td>" . $e(rtrim(rtrim($d['participation_percent'], '0'), '.')) . "%</td>"
            . "<td>" . ($canEdit ? Menu::actionForm('dept_dissoc', ['id' => $d['id'], 'return' => $self . '&tab=org'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") : '') . "</td></tr>";
    }
    echo ($depts ? '' : "<tr><td colspan='4' class='text-center text-secondary py-3'>Nenhum departamento associado.</td></tr>") . "</tbody></table></div>";
    if ($canEdit) {
        $dopts = [];
        foreach (Department::all(true) as $d) {
            $dopts[$d['id']] = $d['code'] . ' · ' . $d['name'];
        }
        echo "<div class='card-body border-top'>" . $form('dept_assoc', ['initiatives_id' => $id, 'return' => $self . '&tab=org'], "<div class='vmo-grid2'>" . $field('Departamento', Menu::select('departments_id', $dopts, '')) . $field('Papel', Menu::select('role', Department::ROLES, 'beneficiary'))
            . $field('Participação (%)', $input('participation_percent', '0', 'inputmode=decimal')) . $field('Responsável', Menu::userSelect('users_id_responsible')) . '</div>', 'Associar') . "</div>";
    }
    echo "</div></div><div class='col-lg-6'><div class='card mb-3'><div class='card-header'><h3 class='card-title'>Equipes e participantes</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Grupo / usuário</th><th>Papel</th><th></th></tr></thead><tbody>";
    foreach (Department::teams($id) as $t) {
        echo "<tr><td>" . ($t['groups_id'] ? "<i class='ti ti-users me-1'></i>" . $e(Dropdown::getDropdownName('glpi_groups', (int) $t['groups_id'])) : "<i class='ti ti-user me-1'></i>" . $e(Db::userName((int) $t['users_id']))) . "</td><td>" . $e($t['role']) . "</td>"
            . "<td>" . ($canEdit ? Menu::actionForm('team_remove', ['id' => $t['id'], 'return' => $self . '&tab=org'], "<button class='btn btn-sm btn-outline-danger'>Remover</button>") : '') . "</td></tr>";
    }
    echo "</tbody></table></div>";
    if ($canEdit) {
        echo "<div class='card-body border-top'>" . $form('team_add', ['initiatives_id' => $id, 'return' => $self . '&tab=org'], "<div class='vmo-grid2'>" . $field('Grupo GLPI', Group::dropdown(['name' => 'groups_id', 'display' => false]))
            . $field('ou Usuário', Menu::userSelect('users_id')) . $field('Papel', $input('role', 'member')) . '</div><div class="form-hint">Associar um grupo não concede acesso à iniciativa.</div>', 'Adicionar') . "</div>";
    }
    echo "</div></div></div>";
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Chamados e projetos do GLPI (vínculo consultivo)</h3></div><div class='card-body'>";
    foreach (ItemLink::forInitiative($id) as $l) {
        echo "<div class='d-flex gap-2 align-items-center mb-1'>" . ($l['url'] ? "<a href='" . $e($l['url']) . "'>" . $e($l['label']) . "</a>" : "<span class='vmo-muted'>" . $e($l['label']) . "</span>")
            . ($canEdit ? Menu::actionForm('link_remove', ['id' => $l['id'], 'return' => $self . '&tab=org'], "<button class='btn btn-sm btn-outline-danger'>×</button>") : '') . "</div>";
    }
    if ($canEdit) {
        echo $form('link_add', ['initiatives_id' => $id, 'return' => $self . '&tab=org'], "<div class='vmo-grid2'>" . $field('Tipo', Menu::select('itemtype', ItemLink::TYPES, 'Ticket')) . $field('Número (ID)', $input('items_id', '', 'type=number min=1 required')) . '</div>', 'Vincular');
    }
    echo "</div></div>";
}

// ================================================================ HISTÓRICO
if ($tab === 'history') {
    echo "<div class='card'><div class='card-header'><h3 class='card-title'>Trilha de auditoria</h3></div><div class='table-responsive'><table class='table card-table'><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Detalhe</th></tr></thead><tbody>";
    $ev = Audit::forItem('Initiative', $id);
    foreach ($ev as $a) {
        $det = json_decode((string) $a['diff'], true);
        $txt = [];
        foreach ((array) $det as $kk => $vv) {
            $txt[] = $kk . ': ' . (is_array($vv) ? (isset($vv['de']) ? ($vv['de'] ?? '∅') . ' → ' . ($vv['para'] ?? '∅') : json_encode($vv, JSON_UNESCAPED_UNICODE)) : $vv);
        }
        echo "<tr><td class='text-nowrap'>" . $e(Menu::datetime($a['date_creation'])) . "</td><td>" . $e(Db::userName((int) $a['users_id'])) . "</td><td>" . $e($a['action']) . "</td><td class='small'>" . $e(mb_strimwidth(implode(' · ', $txt), 0, 300, '…')) . "</td></tr>";
    }
    if (!$ev) {
        echo "<tr><td colspan='4' class='text-center text-secondary py-4'>Sem eventos.</td></tr>";
    }
    echo "</tbody></table></div></div>";
}
Html::footer();
