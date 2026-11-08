<?php

/**
 * Ponto único de gravação do VMO (formulários POST das telas). Valida CSRF e direito, chama a regra de
 * negócio, mostra o resultado como mensagem do GLPI e volta à tela de origem.
 */

use GlpiPlugin\Vmo\Access;
use GlpiPlugin\Vmo\Baseline;
use GlpiPlugin\Vmo\Benefit;
use GlpiPlugin\Vmo\Catalog;
use GlpiPlugin\Vmo\ChangeRequest;
use GlpiPlugin\Vmo\Cost;
use GlpiPlugin\Vmo\Delegation;
use GlpiPlugin\Vmo\Department;
use GlpiPlugin\Vmo\Dependency;
use GlpiPlugin\Vmo\Fx;
use GlpiPlugin\Vmo\Indicator;
use GlpiPlugin\Vmo\Initiative;
use GlpiPlugin\Vmo\ItemLink;
use GlpiPlugin\Vmo\Measurement;
use GlpiPlugin\Vmo\Menu;
use GlpiPlugin\Vmo\Outbox;
use GlpiPlugin\Vmo\Report;
use GlpiPlugin\Vmo\Risk;
use GlpiPlugin\Vmo\RuleException;
use GlpiPlugin\Vmo\Settings;
use GlpiPlugin\Vmo\Task;

Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new \Glpi\Exception\Http\BadRequestHttpException();
}
Session::checkCSRF($_POST);
if (!Access::canView()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$p = $_POST;
$int = fn (string $k): int => (int) ($p[$k] ?? 0);
$str = fn (string $k): string => (string) ($p[$k] ?? '');
$return = (string) ($p['return'] ?? '/front/initiatives.php');
if (!preg_match('#^/front/[a-z_]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $return)) {
    $return = '/front/initiatives.php';
}
$message = 'Salvo.';

try {
    switch ($str('do')) {
        // ---- iniciativa
        case 'initiative_save':
            $id = $int('id');
            if ($id > 0) {
                Initiative::update($id, $p, $int('version'));
                $message = 'Iniciativa atualizada.';
            } else {
                $id = Initiative::create($p);
                $message = 'Iniciativa criada como rascunho.';
                $return = '/front/initiative.php?id=' . $id;
            }
            foreach ($_SESSION['vmo_notices'] ?? [] as $n) {
                Session::addMessageAfterRedirect(htmlspecialchars($n), false, WARNING);
            }
            unset($_SESSION['vmo_notices']);
            break;
        case 'initiative_copy':
            $new = Initiative::copy($int('id'));
            $return = '/front/initiative.php?id=' . $new;
            $message = 'Cópia criada como rascunho.';
            break;
        case 'initiative_archive':
            Initiative::archive($int('id'));
            $return = '/front/initiatives.php';
            $message = 'Iniciativa arquivada (exclusão lógica).';
            break;
        case 'transition':
            Initiative::transition($int('id'), $str('to'), $str('comment'), [
                'review_date' => $str('review_date'), 'forecast_end' => $str('forecast_end'), 'cancel_tasks' => !empty($p['cancel_tasks']), 'exception' => !empty($p['exception']),
            ]);
            $message = 'Situação alterada para "' . Initiative::STATUSES[$str('to')][0] . '".';
            break;

        // ---- tarefas, progresso, dependências
        case 'task_save':
            Task::save($int('initiatives_id'), $int('id'), $p, $int('version'));
            $message = 'Tarefa salva.';
            break;
        case 'task_delete':
            Task::remove($int('initiatives_id'), $int('id'));
            $message = 'Tarefa removida.';
            break;
        case 'task_progress':
            Task::addProgress($int('id'), $p, $int('version'));
            $message = 'Progresso registrado.';
            break;
        case 'task_start':
            Task::start($int('id'));
            $message = 'Tarefa iniciada.';
            break;
        case 'task_cancel':
            Task::setCancelled($int('initiatives_id'), $int('id'), true);
            $message = 'Tarefa cancelada (sai do cálculo; o histórico fica).';
            break;
        case 'task_reactivate':
            Task::setCancelled($int('initiatives_id'), $int('id'), false);
            $message = 'Tarefa reativada.';
            break;
        case 'dep_add':
            Dependency::add($str('kind') ?: 'task', $int('pred_id'), $int('succ_id'), $int('lag_days'), ($p['is_hard'] ?? '1') === '1');
            $message = 'Dependência criada.';
            break;
        case 'dep_remove':
            Dependency::remove($int('id'));
            $message = 'Dependência removida.';
            break;
        case 'dep_exception':
            Dependency::grantException($int('id'), $str('reason'));
            $message = 'Exceção registrada na trilha de auditoria.';
            break;

        // ---- custos
        case 'cost_add':
            Cost::add($int('initiatives_id'), $p);
            $message = 'Lançamento registrado.';
            break;
        case 'cost_reverse':
            Cost::reverse($int('id'), $str('reason'));
            $message = 'Estorno lançado (o original permanece).';
            break;

        // ---- baseline e mudanças
        case 'change_open':
            ChangeRequest::open(Initiative::load($int('initiatives_id')), $str('type'), [
                'planned_start' => $str('planned_start'), 'planned_end' => $str('planned_end'), 'scope' => $str('scope'), 'budget' => $str('budget'),
            ], $str('justification'));
            $message = 'Solicitação de mudança registrada e enviada para aprovação.';
            break;
        case 'change_decide':
            ChangeRequest::decide($int('id'), $str('decision') === 'approve', $str('comment'));
            $message = $str('decision') === 'approve' ? 'Mudança aprovada: nova revisão da baseline gerada.' : 'Mudança rejeitada.';
            break;

        // ---- benefícios
        case 'benefit_save':
            $id = Benefit::save($int('id'), $p, $int('version'));
            $return = '/front/benefit.php?id=' . $id;
            $message = 'Benefício salvo.';
            break;
        case 'benefit_approve':
            Benefit::approve($int('id'));
            $message = 'Benefício aprovado.';
            break;
        case 'benefit_allocate':
            Benefit::allocate($int('benefits_id'), $int('initiatives_id'), $str('percent'));
            $message = 'Alocação registrada.';
            break;
        case 'benefit_unallocate':
            Benefit::unallocate($int('id'));
            $message = 'Alocação removida.';
            break;
        case 'benefit_plan':
            Benefit::setPlan($int('benefits_id'), $str('period'), $str('planned_amount'));
            $message = 'Previsto do período salvo.';
            break;
        case 'measure_declare':
            Measurement::declare($int('benefits_id'), $p);
            $message = 'Medição declarada: aguarda validação de outra pessoa.';
            break;
        case 'measure_decide':
            Measurement::decide($int('id'), $str('decision'), $str('comment'), $str('adjusted'));
            $message = 'Decisão registrada.';
            break;

        // ---- riscos, decisões, vínculos, equipes
        case 'risk_save':
            Risk::save($int('initiatives_id'), $int('id'), $p);
            $message = 'Registro salvo.';
            break;
        case 'decision_add':
            Risk::decide($int('initiatives_id'), $str('title'), $str('decision_text'));
            $message = 'Decisão registrada.';
            break;
        case 'dept_assoc':
            Department::associate($int('initiatives_id'), $int('departments_id'), $str('role'), $str('participation_percent'), $int('users_id_responsible'));
            $message = 'Departamento associado.';
            break;
        case 'dept_dissoc':
            Department::dissociate($int('id'));
            $message = 'Associação removida.';
            break;
        case 'team_add':
            Department::addTeam($int('initiatives_id'), $int('groups_id'), $int('users_id'), $str('role'));
            $message = 'Equipe/participante adicionado (não concede acesso por si só).';
            break;
        case 'team_remove':
            Department::removeTeam($int('id'));
            $message = 'Participante removido.';
            break;
        case 'link_add':
            ItemLink::add($int('initiatives_id'), $str('itemtype'), $int('items_id'));
            $message = 'Vínculo criado.';
            break;
        case 'link_remove':
            ItemLink::remove($int('id'));
            $message = 'Vínculo removido.';
            break;

        // ---- indicadores
        case 'indicator_save':
            Indicator::save($int('id'), $p);
            $message = 'Indicador salvo.';
            break;
        case 'indicator_value':
            Indicator::addValue($int('indicators_id'), $str('period'), $str('observed'));
            $message = 'Medição registrada.';
            break;

        // ---- relatórios e avisos
        case 'report_generate':
            $filters = array_filter(['status' => $str('status'), 'health' => $str('health'), 'manager' => $int('manager') ?: '', 'from' => $str('from'), 'to' => $str('to')], fn ($v) => $v !== '' && $v !== 0);
            $sid = Report::generate($str('title'), $filters, $str('cutoff'));
            $message = 'Relatório gerado (snapshot #' . $sid . ').';
            break;
        case 'inbox_read':
            Outbox::markRead((int) Session::getLoginUserID());
            $message = 'Avisos marcados como lidos.';
            break;

        // ---- configuração
        case 'config_settings':
            if (!Access::canConfigure()) {
                throw RuleException::denied();
            }
            $cur = strtoupper(trim($str('currency')));
            Settings::set([
                'currency' => preg_match('/^[A-Z]{3}$/', $cur) ? $cur : 'BRL', 'cadence_days' => (string) max(1, $int('cadence_days')),
                'report_retention_days' => (string) max(1, $int('report_retention_days')), 'audit_retention_months' => (string) max(1, $int('audit_retention_months')),
                'holidays' => implode(',', array_filter(preg_split('/[\s,;]+/', $str('holidays')) ?: [], fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d))),
            ]);
            GlpiPlugin\Vmo\Audit::log('Settings', 0, 'update', ['moeda' => $cur]);
            $message = 'Configuração salva.';
            break;
        case 'config_thresholds':
            if (!Access::canConfigure()) {
                throw RuleException::denied();
            }
            Settings::saveThresholds($p['th'] ?? []);
            $message = 'Limites de saúde salvos na versão ' . Settings::thresholdsVersion() . ' (relatórios e baselines anteriores mantêm a sua).';
            break;
        case 'catalog_add':
            Catalog::add($str('kind'), $str('code'), $str('name'), $int('entities_id') ?: (int) $_SESSION['glpiactive_entity']);
            $message = 'Item de catálogo criado.';
            break;
        case 'catalog_toggle':
            Catalog::toggle($int('id'));
            break;
        case 'dept_add':
            Department::add($str('code'), $str('name'), $int('parent_id'), $int('users_id_responsible'), $int('entities_id') ?: (int) $_SESSION['glpiactive_entity']);
            $message = 'Departamento criado.';
            break;
        case 'dept_toggle':
            Department::toggle($int('id'));
            break;
        case 'fx_add':
            Fx::add($str('from_currency'), $str('to_currency'), $str('rate_date'), $str('rate'), $str('source'), $int('entities_id') ?: (int) $_SESSION['glpiactive_entity']);
            $message = 'Taxa de câmbio registrada.';
            break;
        case 'delegation_add':
            Delegation::add($int('users_id_from'), $int('users_id_to'), $str('valid_from'), $str('valid_to'), $str('reason'), (int) $_SESSION['glpiactive_entity']);
            $message = 'Delegação cadastrada.';
            break;
        case 'delegation_remove':
            Delegation::remove($int('id'));
            break;
        default:
            throw new RuleException('Operação desconhecida.', 400);
    }
    Session::addMessageAfterRedirect(htmlspecialchars($message), false, INFO);
} catch (RuleException $e) {
    Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, $e->getCode() === 409 ? WARNING : ERROR);
} catch (\Glpi\Exception\Http\HttpException $e) {
    throw $e;
} catch (\Throwable $e) {
    trigger_error('VMO ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), E_USER_WARNING);
    Session::addMessageAfterRedirect('Não foi possível concluir a operação (erro interno registrado).', false, ERROR);
}
Html::redirect(Menu::url($return));
