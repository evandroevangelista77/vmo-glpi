<?php
/**
 * Teste funcional do plugin VMO pela linha de comando: percorre as regras de negócio (cenários CA01–CA13
 * da especificação) contra o banco real e remove o que criou ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/smoke.php
 *
 * Funciona no GLPI 11 e no 12. Precisa do plugin instalado e ativo.
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB, $CFG_GLPI;

use GlpiPlugin\Vmo\{Access, Audit, Baseline, Benefit, Catalog, ChangeRequest, Cost, Db, Delegation, Department, Dependency, Fx, Indicator, Initiative, ItemLink, Measurement, Outbox, Report, Risk, RuleException, Settings, Task};

// ---- sessão como superadministrador (glpi) e, quando preciso, como outras pessoas
$login = function (string $name): int {
    $user = new User();
    if (!$user->getFromDBbyName($name)) {
        throw new RuntimeException("usuário $name não existe");
    }
    $auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
    Session::init($auth);
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    Session::changeActiveEntities(0, true);
    // direitos do plugin: o perfil ativo precisa ter o direito (a instalação concede ao super-admin)
    $_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);
    return (int) $user->getID();
};
$admin = $login('glpi');

$fail = 0;
$ok = function (string $label, $v) use (&$fail): bool {
    echo ($v ? 'OK   ' : 'FALHA') . " $label\n";
    $fail += $v ? 0 : 1;
    return (bool) $v;
};
$throws = function (callable $f, int $code = 0) {
    try { $f(); return null; } catch (RuleException $e) { return $e; } catch (Throwable $e) { return $e; }
};
$rejects = function (string $label, callable $f, ?string $contains = null) use ($ok, $throws): void {
    $ex = $throws($f);
    $good = $ex instanceof RuleException && ($contains === null || stripos($ex->getMessage(), $contains) !== false);
    $ok("$label" . ($ex ? ' (' . mb_strimwidth($ex->getMessage(), 0, 90, '…') . ')' : ' (NÃO rejeitou)'), $good);
};

$tag = 'VMOTEST' . random_int(1000, 9999);
$created = ['initiatives' => [], 'benefits' => []];

// usuários de teste (reaproveita os do laboratório quando existem)
$users = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0, 'name' => ['!=', 'glpi']], 'LIMIT' => 6]) as $u) {
    $users[] = (int) $u['id'];
}
if (count($users) < 3) {
    echo "AVISO: poucos usuários no GLPI para testar segregação (" . count($users) . ")\n";
}
[$u1, $u2, $u3] = [$users[0] ?? $admin, $users[1] ?? $admin, $users[2] ?? $admin];

// ================================================================ RF02 cadastro
$rejects('título vazio impede salvar (RF02)', fn () => Initiative::create(['name' => '  ']), 'título');
$id = Initiative::create(['name' => "Iniciativa $tag", 'description' => 'Teste automático', 'priority' => 4, 'currency' => 'BRL']);
$created['initiatives'][] = $id;
$row = Db::row('initiatives', $id);
$ok("rascunho criado com código {$row['code']}", $row['status'] === 'draft' && preg_match('/^INI-\d{4}$/', $row['code']));
$rejects('código duplicado é rejeitado', fn () => Initiative::create(['name' => 'Outra', 'code' => $row['code']]), 'já existe');
$rejects('submeter sem gestor/patrocinador/objetivo/datas falha', fn () => Initiative::transition($id, 'review'), 'complete');
$rejects('rascunho → encerrada direto é rejeitado no servidor', fn () => Initiative::transition($id, 'closed'), 'não permitida');

// ================================================================ RN03 datas
$rejects('início posterior ao fim é rejeitado', fn () => Initiative::update($id, ['planned_start' => '2027-02-01', 'planned_end' => '2027-01-01'], 1), 'início planejada');
$rejects('data real futura é rejeitada', fn () => Initiative::update($id, ['actual_start' => date('Y-m-d', strtotime('+10 days'))], 1), 'futuro');
$r = $throws(fn () => Initiative::update($id, ['planned_start' => '2020-01-01', 'planned_end' => '2020-03-01'], 1));
$ok('datas planejadas no passado são aceitas com alerta', $r === null && !empty($_SESSION['vmo_notices']));
Initiative::update($id, ['planned_start' => date('Y-m-d', strtotime('+1 day')), 'planned_end' => date('Y-m-d', strtotime('+60 days')), 'users_id_manager' => $u1, 'users_id_sponsor' => $u2, 'objective' => 'Reduzir custo', 'forecast_end' => date('Y-m-d', strtotime('+60 days'))], 2);

// ================================================================ CA09 concorrência
$rejects('CA09: edição concorrente gera conflito (versão antiga)', fn () => Initiative::update($id, ['name' => "Iniciativa $tag v2"], 1), 'alterado por outra');
Initiative::update($id, ['name' => "Iniciativa $tag v2"], 3);
$ok('CA09: a primeira grava a nova versão', (int) Db::row('initiatives', $id)['version'] === 4);

// ================================================================ CA02 ciclo de aprovação
$costId = Cost::add($id, ['kind' => 'forecast', 'amount' => '10.000,00', 'competence_date' => date('Y-m-d'), 'category' => 'Licenças', 'cost_type' => 'capex']);
Initiative::transition($id, 'review');
$ok('CA02: submissão cria a solicitação de aprovação', countElementsInTable(Db::t('approvals'), ['initiatives_id' => $id, 'status' => 'pending']) === 1);
$rejects('CA02: quem submeteu não aprova a própria proposta (RN07)', fn () => Initiative::transition($id, 'approved'), 'submeteu');
// outro aprovador: usa um usuário com direito de aprovar. Cria um perfil/usuário? Usa o patrocinador via direito do perfil ativo.
$sponsorName = (new User())->getFromDB($u2) ? '' : '';
$approverOk = false;
$DB->update(Db::t('approvals'), ['users_id_requester' => $u2 === $admin ? $u1 : $u1], ['initiatives_id' => $id, 'status' => 'pending']); // simula que outra pessoa submeteu
try {
    Initiative::transition($id, 'approved');
    $approverOk = true;
} catch (Throwable $e) {
    echo '     ' . $e->getMessage() . "\n";
}
$ok('CA02: aprovador diferente de quem submeteu aprova e congela a baseline v1', $approverOk && (int) Db::row('initiatives', $id)['baseline_revision'] === 1);
$bl = Baseline::latest($id);
$ok('baseline v1 tem hash íntegro', $bl && Baseline::verify($bl));
$ok('orçamento da baseline = previsão (10.000)', abs(((json_decode($bl['budget_json'], true)['BRL'] ?? 0) - 10000)) < 0.01);

// ================================================================ RN04 baseline imutável
$rejects('prazo planejado não muda direto após a baseline (RN04)', fn () => Initiative::update($id, ['planned_end' => '2030-01-01'], (int) Db::row('initiatives', $id)['version']), 'baseline');
Initiative::update($id, ['forecast_end' => date('Y-m-d', strtotime('+75 days'))], (int) Db::row('initiatives', $id)['version']);
$ok('ajustar a previsão corrente não reescreve a baseline', Baseline::latest($id)['planned_end'] === date('Y-m-d', strtotime('+60 days')));
$cid = ChangeRequest::open(Initiative::load($id), 'schedule', ['planned_end' => date('Y-m-d', strtotime('+90 days'))], 'Mudança de fornecedor');
$rejects('quem solicitou a mudança não a aprova (RN07)', fn () => ChangeRequest::decide($cid, true, ''), 'solicitou');
$DB->update(Db::t('changes'), ['users_id_requester' => $u1], ['id' => $cid]);
ChangeRequest::decide($cid, true, 'ok');
$ok('mudança aprovada gera baseline v2 com comparação', (int) Db::row('initiatives', $id)['baseline_revision'] === 2 && count(Baseline::compare(Baseline::all($id)[0], Baseline::all($id)[1])) >= 1);

// ================================================================ CA03 progresso
Initiative::transition($id, 'running');
$t1 = Task::save($id, 0, ['name' => 'Folha A', 'weight' => '2', 'planned_end' => date('Y-m-d', strtotime('+10 days'))]);
$t2 = Task::save($id, 0, ['name' => 'Folha B', 'weight' => '3', 'planned_end' => date('Y-m-d', strtotime('+20 days'))]);
$t3 = Task::save($id, 0, ['name' => 'Resumo', 'weight' => '1', 'planned_end' => date('Y-m-d', strtotime('+20 days'))]);
$sub = Task::save($id, 0, ['name' => 'Sub', 'weight' => '1', 'parent_id' => $t3]);
Task::addProgress($t1, ['progress' => '50'], (int) Db::row('tasks', $t1)['version']);
Task::addProgress($t2, ['progress' => '100'], (int) Db::row('tasks', $t2)['version']);
Task::addProgress($sub, ['progress' => '100'], (int) Db::row('tasks', $sub)['version']);
$m = Initiative::metricsFor([$id => Db::row('initiatives', $id)])[$id];
// folhas: A(2×50), B(3×100), Sub(1×100) → (100+300+100)/6 = 83.333
$ok('CA03: progresso ponderado das folhas (resumo não duplica peso) = 83,333', abs($m['progress'] - 83.333) < 0.01);
Task::setCancelled($id, $t2, true);
$m2 = Initiative::metricsFor([$id => Db::row('initiatives', $id)])[$id];
// sem B: (100+100)/3 = 66.667
$ok('cancelar a folha B muda a base de cálculo (66,667)', abs($m2['progress'] - 66.667) < 0.01);
Task::setCancelled($id, $t2, false);
$rejects('percentual fora de 0 a 100 falha', fn () => Task::addProgress($t1, ['progress' => '120'], (int) Db::row('tasks', $t1)['version']), 'entre 0 e 100');
$rejects('tarefa resumo não aceita progresso manual', fn () => Task::addProgress($t3, ['progress' => '10'], (int) Db::row('tasks', $t3)['version']), 'resumo');
$rejects('atualização concorrente do progresso gera conflito', fn () => Task::addProgress($t1, ['progress' => '60'], 1), 'alterado por outra');
$rejects('mudança de pai que crie ciclo falha', fn () => Task::save($id, $t3, ['name' => 'Resumo', 'parent_id' => $sub], (int) Db::row('tasks', $t3)['version']), 'ciclo');
// profundidade
$p = $sub;
$deep = $throws(function () use ($id, &$p) { for ($i = 0; $i < 6; $i++) { $p = Task::save($id, 0, ['name' => "n$i", 'parent_id' => $p]); } });
$ok('hierarquia limitada a 5 níveis', $deep instanceof RuleException && stripos($deep->getMessage(), '5 níveis') !== false);

// ================================================================ CA04 grafo
$ta = Task::save($id, 0, ['name' => 'GA', 'weight' => '1']);
$tb = Task::save($id, 0, ['name' => 'GB', 'weight' => '1']);
$tc = Task::save($id, 0, ['name' => 'GC', 'weight' => '1']);
Dependency::add('task', $ta, $tb, 0, true);
Dependency::add('task', $tb, $tc, 0, true);
$rejects('CA04: C→A fecharia ciclo e é rejeitada', fn () => Dependency::add('task', $tc, $ta, 0, true), 'ciclo');
$rejects('autorrelação é rejeitada', fn () => Dependency::add('task', $ta, $ta, 0, true), 'si mesmo');
$rejects('CA04: iniciar B sem concluir A é bloqueado', fn () => Task::start($tb), 'não foi concluída');
$depAB = $DB->request(['FROM' => Db::t('dependencies'), 'WHERE' => ['pred_id' => $ta, 'succ_id' => $tb]])->current()['id'];
Dependency::grantException((int) $depAB, 'Aprovado pelo comitê');
Task::start($tb);
$ok('CA04: exceção auditada libera o início', Db::row('tasks', $tb)['status'] === 'doing' && countElementsInTable(Db::t('audit_events'), ['action' => 'exception', 'itemtype' => 'Dependency']) >= 1);

// ================================================================ CA05/CA06 benefícios
$bid = Benefit::save(0, ['name' => "Economia $tag", 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01', strtotime('-2 months')), 'horizon_end' => date('Y-m-d', strtotime('+10 months')),
    'users_id_owner' => $u1, 'users_id_validator' => $u2, 'source' => 'ERP']);
$created['benefits'][] = $bid;
$rejects('autor do benefício não o aprova (RN07)', fn () => Benefit::approve($bid), 'cadastrou');
$DB->update(Db::t('benefits'), ['users_id_author' => $u1], ['id' => $bid]); // a partir daqui o autor é outra pessoa
$rejects('benefício sem alocação não é aprovado', fn () => Benefit::approve($bid), 'Aloque');
$id2 = Initiative::create(['name' => "Segunda $tag", 'currency' => 'BRL']);
$created['initiatives'][] = $id2;
Benefit::allocate($bid, $id, '60');
Benefit::allocate($bid, $id2, '40');
$rejects('CA06: alocação acima de 100% é rejeitada', fn () => Benefit::allocate($bid, $id, '10'), 'já está alocado');
$id3 = Initiative::create(['name' => "Terceira $tag", 'currency' => 'BRL']);
$created['initiatives'][] = $id3;
$rejects('CA06: 110% no total é rejeitado', fn () => Benefit::allocate($bid, $id3, '10'), '100%');
Benefit::approve($bid);
$ok('benefício aprovado', Db::row('benefits', $bid)['status'] === 'approved');
$period = date('Y-m-01', strtotime('-1 month'));
Benefit::setPlan($bid, substr($period, 0, 7), '100,00');
$mid = Measurement::declare($bid, ['period' => substr($period, 0, 7), 'declared_amount' => '100', 'evidence' => 'Relatório ERP']);
$v0 = Benefit::valueByInitiative([$id, $id2], Db::today());
$ok('CA05: declarado 100 sem validação → validado zero (pendente)', ($v0[$id]['validated']['BRL'] ?? 0) == 0 && Db::row('measurements', $mid)['status'] === 'declared');
// autor da medição = admin (glpi); segregação: o próprio autor não valida
$rejects('CA05: o autor não valida a própria medição', fn () => Measurement::decide($mid, 'validated', ''), 'declarou');
$DB->update(Db::t('measurements'), ['users_id_author' => $u1], ['id' => $mid]);
Measurement::decide($mid, 'validated', '');
$v1 = Benefit::valueByInitiative([$id, $id2], Db::today());
$ok('CA05: revisor diferente valida e o total passa a 100', abs(($v1[$id]['validated']['BRL'] ?? 0) + ($v1[$id2]['validated']['BRL'] ?? 0) - 100) < 0.01);
$ok('CA06: iniciativas mostram 60 e 40 (portfólio soma 100, sem duplicar)', abs($v1[$id]['validated']['BRL'] - 60) < 0.01 && abs($v1[$id2]['validated']['BRL'] - 40) < 0.01);
// retificação: nova revisão, a anterior deixa de somar
$mid2 = Measurement::declare($bid, ['period' => substr($period, 0, 7), 'declared_amount' => '80', 'evidence' => 'Retificação']);
$v2 = Benefit::valueByInitiative([$id, $id2], Db::today());
$ok('retificação cria revisão e zera o validado até nova validação (revisões não se somam)', ($v2[$id]['validated']['BRL'] ?? 0) == 0 && (int) Db::row('measurements', $mid2)['revision'] === 2);
$DB->update(Db::t('measurements'), ['users_id_author' => $u1], ['id' => $mid2]);
Measurement::decide($mid2, 'rejected', 'Evidência insuficiente');
$ok('medição rejeitada não compõe o realizado validado', (Benefit::valueByInitiative([$id], Db::today())[$id]['validated']['BRL'] ?? 0) == 0);

// ================================================================ RN08 / RF10 custos e câmbio
$a1 = Cost::add($id, ['kind' => 'actual', 'amount' => '500', 'competence_date' => date('Y-m-d'), 'source' => 'erp', 'external_key' => "$tag-1"]);
$rejects('CA08: custo repetido por chave externa não duplica', fn () => Cost::add($id, ['kind' => 'actual', 'amount' => '500', 'competence_date' => date('Y-m-d'), 'source' => 'erp', 'external_key' => "$tag-1"]), 'duplicado');
$rev = Cost::reverse($a1, 'Lançado em duplicidade');
$ok('estorno mantém o original e zera o saldo', abs((float) Cost::totals($id)['BRL']['actual']) < 0.01 && countElementsInTable(Db::t('costs'), ['id' => $a1]) === 1);
$rejects('estornar duas vezes é rejeitado', fn () => Cost::reverse($a1, 'x'), 'já foi estornado');
Cost::add($id, ['kind' => 'actual', 'amount' => '100', 'currency' => 'USD', 'competence_date' => date('Y-m-d')]);
$ok('CA12: sem taxa de câmbio não converte (null)', Fx::convert(100, 'USD', 'BRL') === null);
Fx::add('USD', 'BRL', date('Y-m-d'), '5,00', 'Teste', 0);
$ok('com taxa registrada converte 100 USD = 500 BRL', abs(Fx::convert(100, 'USD', 'BRL') - 500) < 0.01);
[$total, $missing] = Fx::consolidate(['BRL' => 10.0, 'EUR' => 5.0], 'BRL');
$ok('moeda sem taxa permanece em total separado', $total == 10.0 && isset($missing['EUR']));

// ================================================================ RF14 riscos, RF13 indicadores
$rk = Risk::save($id, 0, ['title' => 'Atraso do fornecedor', 'probability' => 4, 'impact' => 5, 'status' => 'open']);
$ok('exposição 4×5 = 20 → saúde crítica', Initiative::metricsFor([$id => Db::row('initiatives', $id)])[$id]['health'] === 'red');
$rejects('encerrar risco exige o resultado da resposta', fn () => Risk::save($id, $rk, ['title' => 'Atraso do fornecedor', 'probability' => 4, 'impact' => 5, 'status' => 'closed', 'version' => 1]), 'resultado');
$ix = Indicator::save(0, ['code' => $tag, 'name' => 'NPS', 'baseline_value' => '0', 'target' => '10', 'direction' => 'increase']);
$ok('CA12: indicador sem medição mostra "sem dado" (nunca zero)', Indicator::state(Db::row('indicators', $ix))['status'] === 'nodata');
Indicator::addValue($ix, date('Y-m-d'), '5');
$st = Indicator::state(Db::row('indicators', $ix));
$ok('cumprimento de meta 5 de 10 = 50%', abs($st['progress'] - 50) < 0.01);

// ================================================================ RF22 vínculos / RN09 segregação
$ticket = (int) ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_tickets', 'LIMIT' => 1])->current()['id'] ?? 0);
if ($ticket) {
    $lk = ItemLink::add($id, 'Ticket', $ticket);
    $ok('chamado vinculado (consultivo)', countElementsInTable(Db::t('item_links'), ['id' => $lk]) === 1);
}
$rejects('tipo de objeto fora da lista branca é rejeitado', fn () => ItemLink::add($id, 'User', 1), 'não suportado');
$DB->update(Db::t('initiatives'), ['confidentiality' => 1, 'users_id_manager' => $u3, 'users_id_sponsor' => $u3, 'users_id_author' => $u3], ['id' => $id2]);
// perfil sem direitos de aprovação/configuração não vê iniciativa confidencial de outro gestor
$saved = $_SESSION['glpiactiveprofile'][Access::$rightname];
$_SESSION['glpiactiveprofile'][Access::$rightname] = READ | CREATE | UPDATE;
$vis = Access::visibleInitiatives();
$ok('CA01: iniciativa confidencial de terceiros não aparece na lista (nem nas somas)', !isset($vis[$id2]) && isset($vis[$id]));
$rejects404 = $throws(fn () => Initiative::load($id2));
$ok('CA01: acesso direto por ID devolve 404 (não revela que existe)', $rejects404 instanceof \Glpi\Exception\Http\NotFoundHttpException);
$_SESSION['glpiactiveprofile'][Access::$rightname] = READ;
$ok('sem o direito financeiro, valores ficam ocultos no relatório', Report::portfolio([])['finance'] === false);
$_SESSION['glpiactiveprofile'][Access::$rightname] = $saved;

// ================================================================ CA07 snapshot
$sid = Report::generate("Teste $tag", ['status' => 'running']);
$before = Report::load($sid)['data'];
Initiative::update($id, ['name' => "Iniciativa $tag EDITADA"], (int) Db::row('initiatives', $id)['version']);
$after = Report::load($sid)['data'];
$ok('CA07: editar a iniciativa depois não altera o snapshot', $before['rows'] == $after['rows'] && $before['totals'] == $after['totals']);
$ok('snapshot guarda hash, versão de fórmula e limites', strlen(Db::row('snapshots', $sid)['hash']) === 64 && $after['formula_version'] === '1.0');
$csv = Report::csv($after);
$ok('CSV traz corte, filtros e versões', str_contains($csv, 'Corte') && str_contains($csv, 'Versão das fórmulas'));
$ok('CSV neutraliza fórmulas perigosas', Db::csvCell('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && Db::csvCell('42') === '42');
$pdf = Report::pdf($after);
$ok('PDF gerado (%PDF)', str_starts_with($pdf, '%PDF'));
$docx = Report::docx($after);
$ok('DOCX gerado (zip/OOXML)', str_starts_with($docx, 'PK') && strlen($docx) > 1000);

// ================================================================ RF19 avisos idempotentes / RF20 auditoria
$n1 = Outbox::push($u1, 'late', 'Teste', "$tag-dedup");
$n2 = Outbox::push($u1, 'late', 'Teste', "$tag-dedup");
$ok('CA08: o mesmo evento não gera dois avisos', $n1 === true && $n2 === false);
$ok('RF20: alterar prazo/dados gera evento consultável', count(Audit::forItem('Initiative', $id)) >= 5);
$ok('RF20: trilha tem correlação por requisição', !empty(Audit::forItem('Initiative', $id)[0]['correlation_id']));

// ================================================================ delegação (RN07)
Delegation::add($u1, $u3, date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('+1 day')), 'férias', 0);
$ok('delegação vigente vale; fora da vigência não', Delegation::actsFor($u3, $u1) && !Delegation::actsFor($u1, $u3));
$rejects('delegação com início posterior ao fim é rejeitada', fn () => Delegation::add($u1, $u3, '2030-02-01', '2030-01-01', '', 0), 'vigência');

// ================================================================ ciclo até o fim: entrega, mensuração, encerramento
Task::setCancelled($id, $t1, true); // deixa só tarefas fechadas
foreach (Task::forInitiative($id) as $t) {
    if (in_array($t['status'], ['todo', 'doing'], true)) {
        Task::setCancelled($id, (int) $t['id'], true);
    }
}
$rejects('suspender exige data de revisão', fn () => Initiative::transition($id, 'suspended', 'pausa'), 'revisão');
Initiative::transition($id, 'suspended', 'pausa', ['review_date' => date('Y-m-d', strtotime('+7 days'))]);
Initiative::transition($id, 'running');
Initiative::transition($id, 'delivered');
Initiative::transition($id, 'measuring');
$rejects('encerrar com horizonte em andamento exige dispensa justificada', fn () => Initiative::transition($id, 'closed'), 'horizonte');
Initiative::transition($id, 'closed', 'Dispensa formal do restante');
$ok('iniciativa encerrada', Db::row('initiatives', $id)['status'] === 'closed');
$rejects('iniciativa encerrada não aceita edição', fn () => Initiative::update($id, ['name' => 'x'], (int) Db::row('initiatives', $id)['version']), 'encerrada');
Initiative::transition($id, 'running', 'Reabertura de teste');
$ok('reabertura abre solicitação de mudança (nova versão do plano)', countElementsInTable(Db::t('changes'), ['initiatives_id' => $id, 'status' => 'pending']) >= 1);

// ================================================================ limpeza
$ids = $created['initiatives'];
foreach (['audit_events' => ['itemtype' => 'Initiative', 'items_id' => $ids]] as $t => $w) {
    $DB->delete(Db::t($t), $w);
}
foreach ($ids as $i) {
    foreach (['tasks', 'task_updates', 'costs', 'risks', 'decisions', 'baselines', 'changes', 'approvals', 'allocations', 'item_links', 'initiative_departments', 'participants'] as $t) {
        $DB->delete(Db::t($t), ['initiatives_id' => $i]);
    }
    $DB->delete(Db::t('initiatives'), ['id' => $i]);
}
foreach ($created['benefits'] as $b) {
    $DB->delete(Db::t('measurements'), ['benefits_id' => $b]);
    $DB->delete(Db::t('benefit_plans'), ['benefits_id' => $b]);
    $DB->delete(Db::t('allocations'), ['benefits_id' => $b]);
    $DB->delete(Db::t('benefits'), ['id' => $b]);
}
$DB->delete(Db::t('dependencies'), ['pred_id' => [$ta, $tb, $tc]]);
$DB->delete(Db::t('indicator_values'), ['indicators_id' => $ix]);
$DB->delete(Db::t('indicators'), ['id' => $ix]);
$DB->delete(Db::t('snapshots'), ['title' => "Teste $tag"]);
$DB->delete(Db::t('outbox'), ['dedup_key' => ['LIKE', "%$tag%"]]);
$DB->delete(Db::t('fx_rates'), ['source' => 'Teste']);
$DB->delete(Db::t('delegations'), ['reason' => ['IN', ['férias']]]);
$DB->delete(Db::t('outbox'), ['type' => ['IN', ['status', 'change', 'benefit', 'validation', 'risk']], 'date_creation' => ['>=', date('Y-m-d H:i:s', strtotime('-15 minutes'))]]);

echo $fail ? "\n$fail falha(s)\n" : "\nTodos os testes funcionais passaram.\n";
exit($fail ? 1 : 0);
