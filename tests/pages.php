<?php
/**
 * Renderiza cada tela do VMO pela linha de comando (sessão do superadministrador) e confere que não há
 * erro de PHP e que o conteúdo esperado aparece. Cria uma iniciativa de teste e remove ao final.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tests/pages.php
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB, $CFG_GLPI;

use GlpiPlugin\Vmo\{Access, Benefit, Cost, Db, Initiative, Risk, Task};

$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
Session::changeActiveEntities(0, true);
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);
// o GLPI exige uma sessão registrada em glpi_users_sessions; em linha de comando registramos uma temporária
$testSessionUid = 'vmo-test-' . bin2hex(random_bytes(6));
$_SESSION['login_session_uid'] = $testSessionUid;
$_SESSION['valid_id'] = session_id();
$DB->insert('glpi_users_sessions', ['users_id' => (int) $user->getID(), 'login_session_uid' => $testSessionUid, 'session_file' => 'sess_vmo_test', 'ip_address' => '127.0.0.1',
    'user_agent' => 'vmo-tests', 'auth_type' => 1, 'created_at' => date('Y-m-d H:i:s'), 'last_activity_at' => date('Y-m-d H:i:s')]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/vmo/front/x.php';

$fail = 0;
$ok = function (string $label, bool $v) use (&$fail): void {
    echo ($v ? 'OK   ' : 'FALHA') . " $label\n";
    $fail += $v ? 0 : 1;
};

$tag = 'PG' . random_int(1000, 9999);
$id = Initiative::create(['name' => "Página $tag", 'description' => 'teste de telas', 'users_id_manager' => (int) $user->getID(), 'users_id_sponsor' => (int) $user->getID(), 'objective' => 'x',
    'planned_start' => date('Y-m-d'), 'planned_end' => date('Y-m-d', strtotime('+30 days'))]);
Task::save($id, 0, ['name' => 'Tarefa teste', 'weight' => '1']);
Risk::save($id, 0, ['title' => 'Risco teste', 'probability' => 3, 'impact' => 4]);
Cost::add($id, ['kind' => 'forecast', 'amount' => '1000', 'competence_date' => date('Y-m-d')]);
$bid = Benefit::save(0, ['name' => "Benefício $tag", 'type' => 'financial', 'currency' => 'BRL', 'horizon_start' => date('Y-m-01'), 'horizon_end' => date('Y-m-d', strtotime('+6 months'))]);

$render = function (string $file, array $get = []) use ($root): array {
    $_GET = $get;
    ob_start();
    $err = null;
    try {
        include $root . '/plugins/vmo/front/' . $file;
    } catch (Throwable $e) {
        $err = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    return [ob_get_clean(), $err];
};

$pages = [
    'dashboard.php' => [[], 'Dashboard executivo'],
    'dashboard.php?detail=health' => [['detail' => 'health'], 'Composição'],
    'dashboard.php?detail=planned' => [['detail' => 'planned'], 'Composição'],
    'initiatives.php' => [[], 'Portfólio de iniciativas'],
    'initiative.php?id=0' => [['id' => 0], 'Nova iniciativa'],
    'initiative.php?tab=resumo' => [['id' => $id], "Página $tag"],
    'initiative.php?tab=tasks' => [['id' => $id, 'tab' => 'tasks'], 'Tarefa teste'],
    'initiative.php?tab=deps' => [['id' => $id, 'tab' => 'deps'], 'Entre tarefas'],
    'initiative.php?tab=costs' => [['id' => $id, 'tab' => 'costs'], 'Novo lançamento'],
    'initiative.php?tab=benefits' => [['id' => $id, 'tab' => 'benefits'], 'Alocar benefício'],
    'initiative.php?tab=risks' => [['id' => $id, 'tab' => 'risks'], 'Risco teste'],
    'initiative.php?tab=baseline' => [['id' => $id, 'tab' => 'baseline'], 'Revisões da baseline'],
    'initiative.php?tab=org' => [['id' => $id, 'tab' => 'org'], 'Departamentos'],
    'initiative.php?tab=history' => [['id' => $id, 'tab' => 'history'], 'Trilha de auditoria'],
    'benefits.php' => [[], 'Plano de benefícios'],
    'benefit.php?id=0' => [['id' => 0], 'Novo benefício'],
    'benefit.php' => [['id' => $bid], "Benefício $tag"],
    'indicators.php' => [[], 'Indicadores e metas'],
    'risks.php' => [[], 'Mapa de calor'],
    'reports.php' => [[], 'Gerar relatório'],
    'inbox.php' => [[], 'Aguardando a sua decisão'],
    'config.php' => [[], 'Diagnóstico'],
];
foreach ($pages as $label => [$get, $needle]) {
    [$file] = explode('?', $label);
    [$html, $err] = $render($file, $get);
    $good = $err === null && str_contains($html, $needle) && !preg_match('/Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace/', $html);
    $ok("$label" . ($err ? " → $err" : (str_contains($html, $needle) ? '' : " → não achou \"$needle\"")), $good);
}

// limpeza
$DB->delete('glpi_users_sessions', ['login_session_uid' => $testSessionUid]);
foreach (['tasks', 'risks', 'costs', 'baselines', 'approvals', 'changes'] as $t) {
    $DB->delete(Db::t($t), ['initiatives_id' => $id]);
}
$DB->delete(Db::t('initiatives'), ['id' => $id]);
$DB->delete(Db::t('benefits'), ['id' => $bid]);
$DB->delete(Db::t('audit_events'), ['itemtype' => 'Initiative', 'items_id' => $id]);
echo $fail ? "\n$fail falha(s)\n" : "\nTodas as telas renderizaram.\n";
exit($fail ? 1 : 0);
