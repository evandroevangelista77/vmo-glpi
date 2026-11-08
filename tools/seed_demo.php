<?php
/**
 * Dados de demonstração do VMO (todos marcados "[Demo]"). Uso, no servidor do GLPI:
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tools/seed_demo.php          # cria (não repete se já existir)
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tools/seed_demo.php --reset  # remove os dados de demonstração
 *
 * Atalho consciente: as aprovações exigem "outra pessoa"; o script ajusta direto no banco quem submeteu/declarou
 * para que o superadministrador possa decidir. Em uso real isso é feito por pessoas diferentes.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

use GlpiPlugin\Vmo\{Access, Benefit, Catalog, Cost, Db, Department, Dependency, Indicator, Initiative, Measurement, Risk, Task};

$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
Session::changeActiveEntities(0, true);
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);

$demoIds = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Db::t('initiatives'), 'WHERE' => ['name' => ['LIKE', '[Demo]%']]]), false), 'id');
$demoBenefits = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Db::t('benefits'), 'WHERE' => ['name' => ['LIKE', '[Demo]%']]]), false), 'id');
$demoInd = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => Db::t('indicators'), 'WHERE' => ['name' => ['LIKE', '[Demo]%']]]), false), 'id');

if (in_array('--reset', $argv ?? [], true) || $demoIds) {
    if (!in_array('--reset', $argv ?? [], true)) {
        echo "Já existem dados de demonstração. Use --reset para removê-los.\n";
        exit(0);
    }
    foreach ($demoIds as $i) {
        foreach (['tasks', 'task_updates', 'costs', 'risks', 'decisions', 'baselines', 'changes', 'approvals', 'allocations', 'item_links', 'initiative_departments', 'participants'] as $t) {
            $DB->delete(Db::t($t), ['initiatives_id' => $i]);
        }
        $DB->delete(Db::t('audit_events'), ['itemtype' => 'Initiative', 'items_id' => $i]);
        $DB->delete(Db::t('initiatives'), ['id' => $i]);
    }
    foreach ($demoBenefits as $b) {
        foreach (['measurements', 'benefit_plans', 'allocations'] as $t) {
            $DB->delete(Db::t($t), ['benefits_id' => $b]);
        }
        $DB->delete(Db::t('benefits'), ['id' => $b]);
    }
    foreach ($demoInd as $i) {
        $DB->delete(Db::t('indicator_values'), ['indicators_id' => $i]);
        $DB->delete(Db::t('indicators'), ['id' => $i]);
    }
    $DB->delete(Db::t('catalogs'), ['name' => ['LIKE', '[Demo]%']]);
    $DB->delete(Db::t('departments'), ['name' => ['LIKE', '[Demo]%']]);
    $DB->delete(Db::t('outbox'), ['entities_id' => 0]);
    echo "Dados de demonstração removidos.\n";
    exit(0);
}

// pessoas do laboratório
$names = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0]]) as $u) {
    $names[$u['name']] = (int) $u['id'];
}
$me = (int) $user->getID();
$pick = fn (string ...$n) => array_values(array_filter(array_map(fn ($x) => $names[$x] ?? null, $n)))[0] ?? $me;
$tech = $pick('tech'); $normal = $pick('normal', 'post-only'); $other = $pick('post-only', 'normal');
$today = date('Y-m-d');
$d = fn (int $days) => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));

// catálogos e departamentos
$oe1 = Catalog::add('objective', 'OE-01', '[Demo] Reduzir custo operacional', 0);
$oe2 = Catalog::add('objective', 'OE-02', '[Demo] Elevar a satisfação do cliente', 0);
$cat1 = Catalog::add('category', 'CAT-01', '[Demo] Transformação digital', 0);
$cat2 = Catalog::add('category', 'CAT-02', '[Demo] Eficiência operacional', 0);
$ti = Department::add('TI', '[Demo] Tecnologia da Informação', 0, $tech, 0);
$fin = Department::add('FIN', '[Demo] Financeiro', 0, $normal, 0);
$ops = Department::add('OPS', '[Demo] Operações', 0, $other, 0);

/** Avança a iniciativa pelo ciclo de aprovação (o aprovador precisa ser outra pessoa que o solicitante). */
$approve = function (int $id) use ($DB, $tech): void {
    Initiative::transition($id, 'review');
    $DB->update(Db::t('approvals'), ['users_id_requester' => $tech], ['initiatives_id' => $id, 'status' => 'pending']);
    Initiative::transition($id, 'approved', 'Aprovada (demonstração)');
};

// ------------------------------------------------------------------ A: Automação do atendimento (em execução, amarela)
$a = Initiative::create(['name' => '[Demo] Automação do atendimento N1', 'description' => 'Automatizar respostas de nível 1 com base de conhecimento e roteamento inteligente.',
    'problem' => 'Fila de nível 1 com tempo médio de resposta acima do acordado.', 'objective' => 'Reduzir em 40% os chamados que chegam ao N2.', 'justification' => 'Ganho de capacidade da equipe de suporte.',
    'business_case' => 'Investimento de R$ 80 mil com retorno estimado de R$ 10 mil/mês em horas liberadas.', 'catalogs_id_objective' => $oe1, 'catalogs_id_category' => $cat1, 'priority' => 4,
    'users_id_manager' => $tech, 'users_id_sponsor' => $normal, 'planned_start' => $d(-75), 'planned_end' => $d(60), 'forecast_end' => $d(60), 'currency' => 'BRL']);
Department::associate($a, $ti, 'executor', '60', $tech);
Department::associate($a, $ops, 'beneficiary', '40', $other);
foreach ([['Licenças da plataforma', 'capex', 30000], ['Implantação', 'opex', 35000], ['Treinamento', 'opex', 15000]] as [$cat, $type, $v]) {
    Cost::add($a, ['kind' => 'forecast', 'amount' => (string) $v, 'competence_date' => $d(-70), 'category' => $cat, 'cost_type' => $type, 'description' => "Previsão: $cat"]);
}
$approve($a);
Initiative::transition($a, 'running');
$DB->update(Db::t('initiatives'), ['actual_start' => $d(-70)], ['id' => $a]);
$root = Task::save($a, 0, ['name' => 'Levantamento e desenho', 'weight' => '1']);
$t1 = Task::save($a, 0, ['name' => 'Mapear os 30 assuntos mais frequentes', 'parent_id' => $root, 'weight' => '2', 'users_id_responsible' => $tech, 'planned_start' => $d(-70), 'planned_end' => $d(-45)]);
$t2 = Task::save($a, 0, ['name' => 'Desenhar fluxos de atendimento', 'parent_id' => $root, 'weight' => '3', 'users_id_responsible' => $tech, 'planned_start' => $d(-45), 'planned_end' => $d(-20)]);
$t3 = Task::save($a, 0, ['name' => 'Configurar a plataforma', 'weight' => '4', 'users_id_responsible' => $normal, 'planned_start' => $d(-20), 'planned_end' => $d(-5)]);
$t4 = Task::save($a, 0, ['name' => 'Piloto com a equipe N1', 'weight' => '3', 'users_id_responsible' => $tech, 'planned_start' => $d(-5), 'planned_end' => $d(25)]);
$t5 = Task::save($a, 0, ['name' => 'Go-live', 'weight' => '0', 'is_milestone' => 1, 'users_id_responsible' => $tech, 'planned_start' => $d(40), 'planned_end' => $d(40)]);
Dependency::add('task', $t3, $t4, 2, true);
Dependency::add('task', $t4, $t5, 0, true);
foreach ([$t1, $t2] as $t) {
    Task::addProgress($t, ['progress' => '100', 'reference_date' => $d(-30), 'comment' => 'Concluído', 'evidence' => 'Ata da reunião'], (int) Db::row('tasks', $t)['version']);
}
Task::addProgress($t3, ['progress' => '70', 'effort_delta' => '24', 'comment' => 'Falta integrar o catálogo de serviços'], (int) Db::row('tasks', $t3)['version']);
foreach ([[-60, 18000], [-30, 22000], [-5, 9000]] as [$off, $v]) {
    Cost::add($a, ['kind' => 'actual', 'amount' => (string) $v, 'competence_date' => $d($off), 'category' => 'Implantação', 'cost_type' => 'opex', 'description' => 'Lançamento real', 'source' => 'erp', 'external_key' => 'DEMO-' . abs($off)]);
}
Risk::save($a, 0, ['title' => 'Integração com o catálogo de serviços pode atrasar', 'probability' => 4, 'impact' => 3, 'response' => 'Alocar um desenvolvedor adicional', 'users_id_owner' => $tech, 'due_date' => $d(10)]);
Risk::decide($a, 'Manter o escopo do piloto', 'Comitê decidiu manter 30 assuntos no piloto e ampliar depois do go-live.');
$DB->update(Db::t('initiatives'), ['forecast_end' => $d(68)], ['id' => $a]); // previsão corrente atrasa sem mexer na baseline

// benefício financeiro com medições
$bf = Benefit::save(0, ['name' => '[Demo] Horas de suporte liberadas', 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01', strtotime('-4 months')), 'horizon_end' => $d(300),
    'users_id_owner' => $normal, 'users_id_validator' => $other, 'source' => 'Apontamento de horas do ERP', 'method' => 'Horas liberadas × custo-hora médio', 'unit' => 'R$']);
$DB->update(Db::t('benefits'), ['users_id_author' => $tech], ['id' => $bf]);
Benefit::allocate($bf, $a, '100');
Benefit::approve($bf);
for ($m = 4; $m >= -3; $m--) {
    Benefit::setPlan($bf, date('Y-m', strtotime("-$m months")), '10000');
}
foreach ([4, 3, 2] as $m) {
    $mid = Measurement::declare($bf, ['period' => date('Y-m', strtotime("-$m months")), 'declared_amount' => (string) (8000 + $m * 500), 'evidence' => 'Relatório de horas ' . date('m/Y', strtotime("-$m months"))]);
    $DB->update(Db::t('measurements'), ['users_id_author' => $normal], ['id' => $mid]);
    Measurement::decide($mid, 'validated', 'Conferido contra o ERP');
}
Measurement::declare($bf, ['period' => date('Y-m', strtotime('-1 month')), 'declared_amount' => '9500', 'evidence' => 'Relatório de horas (aguardando conferência)']);

// ------------------------------------------------------------------ B: Migração para nuvem (em análise)
$b = Initiative::create(['name' => '[Demo] Migração dos servidores de arquivos para a nuvem', 'description' => 'Migrar o servidor de arquivos para armazenamento em nuvem com backup gerenciado.',
    'objective' => 'Reduzir custo de infraestrutura e risco de indisponibilidade.', 'catalogs_id_objective' => $oe1, 'catalogs_id_category' => $cat2, 'priority' => 3,
    'users_id_manager' => $tech, 'users_id_sponsor' => $normal, 'planned_start' => $d(15), 'planned_end' => $d(120), 'currency' => 'BRL']);
Cost::add($b, ['kind' => 'forecast', 'amount' => '120000', 'competence_date' => $d(20), 'category' => 'Migração', 'cost_type' => 'capex']);
Risk::save($b, 0, ['title' => 'Janela de migração curta', 'probability' => 3, 'impact' => 5, 'users_id_owner' => $tech]);
Initiative::transition($b, 'review');

// ------------------------------------------------------------------ C: Pesquisa de satisfação (em mensuração, benefício não financeiro)
$c = Initiative::create(['name' => '[Demo] Pesquisa de satisfação pós-atendimento', 'description' => 'Pesquisa automática ao fechar o chamado.', 'objective' => 'Elevar o NPS do suporte.',
    'catalogs_id_objective' => $oe2, 'catalogs_id_category' => $cat1, 'priority' => 2, 'users_id_manager' => $normal, 'users_id_sponsor' => $tech, 'planned_start' => $d(-120), 'planned_end' => $d(-20), 'currency' => 'BRL']);
$approve($c);
Initiative::transition($c, 'running');
$tc = Task::save($c, 0, ['name' => 'Publicar a pesquisa', 'weight' => '1', 'users_id_responsible' => $normal, 'planned_end' => $d(-30)]);
Task::addProgress($tc, ['progress' => '100', 'reference_date' => $d(-25)], (int) Db::row('tasks', $tc)['version']);
Initiative::transition($c, 'delivered');
Initiative::transition($c, 'measuring');
$bn = Benefit::save(0, ['name' => '[Demo] Satisfação do cliente (NPS do suporte)', 'type' => 'nonfinancial', 'unit' => 'pontos de NPS', 'direction' => 'increase', 'baseline_value' => '40', 'target' => '60',
    'horizon_start' => date('Y-m-01', strtotime('-3 months')), 'horizon_end' => $d(180), 'rubric' => 'NPS apurado mensalmente pela pesquisa pós-atendimento (promotores − detratores).',
    'users_id_owner' => $normal, 'users_id_validator' => $other, 'source' => 'Pesquisa pós-atendimento']);
$DB->update(Db::t('benefits'), ['users_id_author' => $tech], ['id' => $bn]);
Benefit::allocate($bn, $c, '100');
Benefit::approve($bn);
$mn = Measurement::declare($bn, ['period' => date('Y-m', strtotime('-2 months')), 'observed_value' => '48', 'evidence' => 'Relatório da pesquisa']);
$DB->update(Db::t('measurements'), ['users_id_author' => $normal], ['id' => $mn]);
Measurement::decide($mn, 'validated', '');

// ------------------------------------------------------------------ indicador global
$ix = Indicator::save(0, ['code' => 'IND-NPS', 'name' => '[Demo] NPS do suporte', 'unit' => 'pontos', 'direction' => 'increase', 'baseline_value' => '40', 'target' => '60', 'cadence_days' => 30,
    'users_id_owner' => $normal, 'source' => 'Pesquisa pós-atendimento']);
foreach ([[-90, 41], [-60, 44], [-30, 48]] as [$off, $v]) {
    Indicator::addValue($ix, $d($off), (string) $v);
}
$ix2 = Indicator::save(0, ['code' => 'IND-N1', 'name' => '[Demo] % de chamados resolvidos no N1', 'unit' => '%', 'direction' => 'increase', 'baseline_value' => '55', 'target' => '75', 'cadence_days' => 30, 'initiatives_id' => $a, 'users_id_owner' => $tech]);

echo "Dados de demonstração criados: 3 iniciativas ([Demo] Automação do atendimento N1, Migração para nuvem, Pesquisa de satisfação), 2 benefícios, 2 indicadores, catálogos e departamentos.\n";
