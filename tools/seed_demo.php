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

// ------------------------------------------------------------------ D: ERP financeiro (em execução, CRÍTICA: atraso, estouro de custo e risco alto)
$dd = Initiative::create(['name' => '[Demo] Implantação do ERP financeiro', 'description' => 'Substituir o sistema financeiro legado por um ERP integrado a compras e fiscal.',
    'problem' => 'Fechamento contábil manual e demorado, com retrabalho entre áreas.', 'objective' => 'Fechar o mês em 3 dias úteis e eliminar planilhas paralelas.', 'justification' => 'Risco fiscal e custo de retrabalho.',
    'business_case' => 'Investimento de R$ 300 mil, retorno de R$ 20 mil/mês em retrabalho evitado.', 'catalogs_id_objective' => $oe1, 'catalogs_id_category' => $cat1, 'priority' => 5,
    'users_id_manager' => $normal, 'users_id_sponsor' => $tech, 'planned_start' => $d(-150), 'planned_end' => $d(30), 'forecast_end' => $d(30), 'currency' => 'BRL']);
Department::associate($dd, $fin, 'executor', '70', $normal);
Department::associate($dd, $ti, 'executor', '30', $tech);
foreach ([['Licenças do ERP', 'capex', 140000], ['Consultoria de implantação', 'opex', 110000], ['Migração de dados', 'opex', 50000]] as [$cat, $type, $v]) {
    Cost::add($dd, ['kind' => 'forecast', 'amount' => (string) $v, 'competence_date' => $d(-145), 'category' => $cat, 'cost_type' => $type, 'description' => "Previsão: $cat"]);
}
$approve($dd);
Initiative::transition($dd, 'running');
$DB->update(Db::t('initiatives'), ['actual_start' => $d(-148)], ['id' => $dd]);
$dt1 = Task::save($dd, 0, ['name' => 'Levantamento de requisitos fiscais', 'weight' => '2', 'users_id_responsible' => $normal, 'planned_start' => $d(-148), 'planned_end' => $d(-110)]);
$dt2 = Task::save($dd, 0, ['name' => 'Parametrização do plano de contas', 'weight' => '3', 'users_id_responsible' => $normal, 'planned_start' => $d(-110), 'planned_end' => $d(-60)]);
$dt3 = Task::save($dd, 0, ['name' => 'Migração dos dados históricos', 'weight' => '4', 'users_id_responsible' => $tech, 'planned_start' => $d(-60), 'planned_end' => $d(-5)]);
$dt4 = Task::save($dd, 0, ['name' => 'Testes integrados e homologação', 'weight' => '3', 'users_id_responsible' => $normal, 'planned_start' => $d(-5), 'planned_end' => $d(20)]);
$dt5 = Task::save($dd, 0, ['name' => 'Virada para produção', 'weight' => '0', 'is_milestone' => 1, 'users_id_responsible' => $tech, 'planned_start' => $d(30), 'planned_end' => $d(30)]);
Dependency::add('task', $dt3, $dt4, 0, true);
Dependency::add('task', $dt4, $dt5, 0, true);
foreach ([$dt1, $dt2] as $t) {
    Task::addProgress($t, ['progress' => '100', 'reference_date' => $d(-50), 'comment' => 'Concluído'], (int) Db::row('tasks', $t)['version']);
}
Task::addProgress($dt3, ['progress' => '45', 'effort_delta' => '60', 'comment' => 'Qualidade dos dados históricos exigiu limpeza adicional'], (int) Db::row('tasks', $dt3)['version']);
foreach ([[-120, 60000], [-70, 85000], [-20, 70000]] as [$off, $v]) {
    Cost::add($dd, ['kind' => 'actual', 'amount' => (string) $v, 'competence_date' => $d($off), 'category' => 'Consultoria de implantação', 'cost_type' => 'opex', 'description' => 'Lançamento real', 'source' => 'erp', 'external_key' => 'DEMO-D-' . abs($off)]);
}
Cost::add($dd, ['kind' => 'forecast', 'amount' => '95000', 'competence_date' => $d(-10), 'category' => 'Consultoria de implantação', 'cost_type' => 'opex', 'description' => 'Reforço de consultoria para a limpeza de dados']);
Risk::save($dd, 0, ['title' => 'Qualidade dos dados históricos compromete a virada', 'probability' => 5, 'impact' => 4, 'response' => 'Força-tarefa de saneamento e congelamento de lançamentos', 'users_id_owner' => $normal, 'due_date' => $d(15)]);
Risk::save($dd, 0, ['title' => 'Dependência de um único consultor sênior', 'probability' => 3, 'impact' => 4, 'users_id_owner' => $tech]);
Risk::decide($dd, 'Aprovar reforço de consultoria', 'Comitê aprovou R$ 95 mil adicionais para sanear os dados e proteger a data de virada.');
$DB->update(Db::t('initiatives'), ['forecast_end' => $d(78)], ['id' => $dd]); // virada atrasa em relação à baseline
$bd = Benefit::save(0, ['name' => '[Demo] Retrabalho evitado no fechamento contábil', 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01', strtotime('+2 months')), 'horizon_end' => $d(420),
    'users_id_owner' => $normal, 'users_id_validator' => $other, 'source' => 'Apontamento de horas da contabilidade', 'method' => 'Horas evitadas × custo-hora médio', 'unit' => 'R$']);
$DB->update(Db::t('benefits'), ['users_id_author' => $tech], ['id' => $bd]);
Benefit::allocate($bd, $dd, '100');
Benefit::approve($bd);
for ($m = 2; $m <= 12; $m++) { // plano dentro do horizonte (começa daqui a 2 meses)
    Benefit::setPlan($bd, date('Y-m', strtotime("+$m months")), '20000');
}

// ------------------------------------------------------------------ E: Segurança da informação (em execução, NO PRAZO)
$e = Initiative::create(['name' => '[Demo] Programa de segurança da informação', 'description' => 'MFA corporativo, gestão de vulnerabilidades e treinamento anual de conscientização.',
    'problem' => 'Exposição a phishing e contas sem segundo fator.', 'objective' => 'Cobrir 100% dos usuários com MFA e reduzir incidentes de phishing em 60%.', 'justification' => 'Exigência de clientes e auditoria.',
    'business_case' => 'Investimento de R$ 150 mil contra perdas potenciais muito superiores.', 'catalogs_id_objective' => $oe2, 'catalogs_id_category' => $cat2, 'priority' => 4,
    'users_id_manager' => $tech, 'users_id_sponsor' => $normal, 'planned_start' => $d(-90), 'planned_end' => $d(75), 'forecast_end' => $d(75), 'currency' => 'BRL']);
Department::associate($e, $ti, 'executor', '100', $tech);
foreach ([['Licenças MFA e EDR', 'opex', 90000], ['Treinamentos', 'opex', 35000], ['Testes de invasão', 'opex', 25000]] as [$cat, $type, $v]) {
    Cost::add($e, ['kind' => 'forecast', 'amount' => (string) $v, 'competence_date' => $d(-85), 'category' => $cat, 'cost_type' => $type, 'description' => "Previsão: $cat"]);
}
$approve($e);
Initiative::transition($e, 'running');
$DB->update(Db::t('initiatives'), ['actual_start' => $d(-88)], ['id' => $e]);
$et1 = Task::save($e, 0, ['name' => 'Implantar MFA para todos os usuários', 'weight' => '4', 'users_id_responsible' => $tech, 'planned_start' => $d(-88), 'planned_end' => $d(-30)]);
$et2 = Task::save($e, 0, ['name' => 'Implantar EDR nas estações', 'weight' => '3', 'users_id_responsible' => $tech, 'planned_start' => $d(-60), 'planned_end' => $d(10)]);
$et3 = Task::save($e, 0, ['name' => 'Campanha de conscientização', 'weight' => '2', 'users_id_responsible' => $normal, 'planned_start' => $d(-30), 'planned_end' => $d(30)]);
$et4 = Task::save($e, 0, ['name' => 'Teste de invasão externo', 'weight' => '1', 'is_milestone' => 1, 'users_id_responsible' => $tech, 'planned_start' => $d(60), 'planned_end' => $d(60)]);
Task::addProgress($et1, ['progress' => '100', 'reference_date' => $d(-28), 'comment' => 'MFA em 100% dos usuários'], (int) Db::row('tasks', $et1)['version']);
Task::addProgress($et2, ['progress' => '80', 'effort_delta' => '30', 'comment' => 'Faltam as estações remotas'], (int) Db::row('tasks', $et2)['version']);
Task::addProgress($et3, ['progress' => '50', 'comment' => 'Duas de quatro turmas concluídas'], (int) Db::row('tasks', $et3)['version']);
foreach ([[-70, 30000], [-35, 28000], [-8, 12000]] as [$off, $v]) {
    Cost::add($e, ['kind' => 'actual', 'amount' => (string) $v, 'competence_date' => $d($off), 'category' => 'Licenças MFA e EDR', 'cost_type' => 'opex', 'description' => 'Lançamento real', 'source' => 'erp', 'external_key' => 'DEMO-E-' . abs($off)]);
}
Risk::save($e, 0, ['title' => 'Resistência dos usuários ao segundo fator', 'probability' => 2, 'impact' => 2, 'response' => 'Comunicação interna e suporte no primeiro mês', 'users_id_owner' => $tech]);
$ix3 = Indicator::save(0, ['code' => 'IND-MFA', 'name' => '[Demo] % de usuários com MFA ativo', 'unit' => '%', 'direction' => 'increase', 'baseline_value' => '20', 'target' => '100', 'cadence_days' => 30,
    'initiatives_id' => $e, 'users_id_owner' => $tech, 'source' => 'Console de identidade']);
foreach ([[-80, 25], [-50, 70], [-20, 100]] as [$off, $v]) {
    Indicator::addValue($ix3, $d($off), (string) $v);
}

// ------------------------------------------------------------------ F: Renovação do parque de notebooks (entregue, benefício validado)
$f = Initiative::create(['name' => '[Demo] Renovação do parque de notebooks', 'description' => 'Troca de 120 notebooks com mais de 5 anos.', 'objective' => 'Reduzir chamados de hardware e tempo parado.',
    'catalogs_id_objective' => $oe1, 'catalogs_id_category' => $cat2, 'priority' => 3, 'users_id_manager' => $other, 'users_id_sponsor' => $normal,
    'planned_start' => $d(-200), 'planned_end' => $d(-40), 'forecast_end' => $d(-40), 'currency' => 'BRL']);
Cost::add($f, ['kind' => 'forecast', 'amount' => '420000', 'competence_date' => $d(-195), 'category' => 'Equipamentos', 'cost_type' => 'capex', 'description' => 'Previsão: 120 notebooks']);
$approve($f);
Initiative::transition($f, 'running');
$ft = Task::save($f, 0, ['name' => 'Compra e entrega dos equipamentos', 'weight' => '1', 'users_id_responsible' => $other, 'planned_end' => $d(-60)]);
Task::addProgress($ft, ['progress' => '100', 'reference_date' => $d(-45), 'comment' => 'Entrega concluída'], (int) Db::row('tasks', $ft)['version']);
Cost::add($f, ['kind' => 'actual', 'amount' => '405000', 'competence_date' => $d(-50), 'category' => 'Equipamentos', 'cost_type' => 'capex', 'description' => 'Lançamento real', 'source' => 'erp', 'external_key' => 'DEMO-F-50']);
Initiative::transition($f, 'delivered');
Initiative::transition($f, 'measuring');
$bf2 = Benefit::save(0, ['name' => '[Demo] Redução de chamados de hardware', 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01', strtotime('-5 months')), 'horizon_end' => $d(240),
    'users_id_owner' => $other, 'users_id_validator' => $normal, 'source' => 'Chamados de hardware do GLPI', 'method' => 'Chamados evitados × custo médio por chamado', 'unit' => 'R$']);
$DB->update(Db::t('benefits'), ['users_id_author' => $tech], ['id' => $bf2]);
Benefit::allocate($bf2, $f, '100');
Benefit::approve($bf2);
for ($m = 5; $m >= -2; $m--) {
    Benefit::setPlan($bf2, date('Y-m', strtotime("-$m months")), '12000');
}
foreach ([5, 4, 3, 2] as $m) {
    $mid = Measurement::declare($bf2, ['period' => date('Y-m', strtotime("-$m months")), 'declared_amount' => (string) (11000 + $m * 300), 'evidence' => 'Relatório de chamados ' . date('m/Y', strtotime("-$m months"))]);
    $DB->update(Db::t('measurements'), ['users_id_author' => $other], ['id' => $mid]);
    Measurement::decide($mid, 'validated', 'Conferido contra o GLPI');
}

// ------------------------------------------------------------------ G: Portal do cliente (suspensa, aguardando orçamento)
$g = Initiative::create(['name' => '[Demo] Portal de autoatendimento do cliente', 'description' => 'Portal para abertura e acompanhamento de chamados pelo cliente.', 'objective' => 'Reduzir ligações ao suporte em 30%.',
    'catalogs_id_objective' => $oe2, 'catalogs_id_category' => $cat1, 'priority' => 3, 'users_id_manager' => $normal, 'users_id_sponsor' => $tech,
    'planned_start' => $d(-60), 'planned_end' => $d(150), 'forecast_end' => $d(150), 'currency' => 'BRL']);
Cost::add($g, ['kind' => 'forecast', 'amount' => '180000', 'competence_date' => $d(-55), 'category' => 'Desenvolvimento', 'cost_type' => 'capex', 'description' => 'Previsão: desenvolvimento do portal']);
Risk::save($g, 0, ['title' => 'Orçamento do ano ainda não liberado', 'probability' => 4, 'impact' => 3, 'users_id_owner' => $normal]);
$approve($g);
Initiative::transition($g, 'running');
Initiative::transition($g, 'suspended', 'Aguardando liberação do orçamento do próximo ciclo.');

// ------------------------------------------------------------------ H: Programa de IA generativa (rascunho)
Initiative::create(['name' => '[Demo] Programa de IA generativa no atendimento', 'description' => 'Avaliar assistentes de IA para apoiar os analistas de suporte.', 'objective' => 'Reduzir o tempo médio de resolução.',
    'catalogs_id_objective' => $oe1, 'catalogs_id_category' => $cat1, 'priority' => 2, 'users_id_manager' => $tech, 'users_id_sponsor' => $normal,
    'planned_start' => $d(45), 'planned_end' => $d(240), 'currency' => 'BRL']);

echo "Dados de demonstração criados: 8 iniciativas (críticas, no prazo, entregue, suspensa e rascunho), 4 benefícios, 3 indicadores, catálogos e departamentos.\n";
