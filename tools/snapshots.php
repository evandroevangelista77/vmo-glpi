<?php
/**
 * Gera instantâneos HTML das telas do VMO (com os dados "[Demo]") em <glpi>/public/vmo-snaps/, para tirar
 * prints com um navegador sem login (usados no manual). Remova depois com --clean.
 *
 *   sudo -u www-data env GLPI_ROOT=/var/www/glpi php tools/snapshots.php [--clean]
 */
$root = getenv('GLPI_ROOT') ?: '/var/www/glpi';
$dir = $root . '/public/vmo-snaps';
if (in_array('--clean', $argv ?? [], true)) {
    foreach (glob("$dir/*") ?: [] as $f) { @unlink($f); }
    @rmdir($dir);
    echo "removido\n";
    exit(0);
}
$only = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel('production');
$kernel->boot();
global $DB;

use GlpiPlugin\Vmo\{Access, Db};

$user = new User(); $user->getFromDBbyName('glpi');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
Session::init($auth);
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
Session::changeActiveEntities(0, true);
$_SESSION['glpiactiveprofile'][Access::$rightname] = (int) (ProfileRight::getProfileRights($_SESSION['glpiactiveprofile']['id'], [Access::$rightname])[Access::$rightname] ?? 0);
$uid = 'vmo-snap-' . bin2hex(random_bytes(6));
$_SESSION['login_session_uid'] = $uid;
$_SESSION['valid_id'] = session_id();
$DB->insert('glpi_users_sessions', ['users_id' => (int) $user->getID(), 'login_session_uid' => $uid, 'session_file' => 'sess_vmo_snap', 'ip_address' => '127.0.0.1',
    'user_agent' => 'vmo-snaps', 'auth_type' => 1, 'created_at' => date('Y-m-d H:i:s'), 'last_activity_at' => date('Y-m-d H:i:s')]);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/plugins/vmo/front/x.php';

$byName = fn (string $table, string $like) => (int) ($DB->request(['SELECT' => ['id'], 'FROM' => Db::t($table), 'WHERE' => ['name' => ['LIKE', $like]], 'LIMIT' => 1])->current()['id'] ?? 0);
$a = $byName('initiatives', '[Demo] Automação%');
$b = $byName('initiatives', '[Demo] Migração%');
$c = $byName('initiatives', '[Demo] Pesquisa%');
$bf = $byName('benefits', '[Demo] Horas%');
$bn = $byName('benefits', '[Demo] Satisfação%');
if (!$a || !$bf) {
    fwrite(STDERR, "Carregue antes os dados de demonstração (tools/seed_demo.php).\n");
    exit(1);
}

$shots = [
    'dashboard'        => ['dashboard.php', []],
    'dashboard-valor'  => ['dashboard.php', ['detail' => 'validated']],
    'portfolio'        => ['initiatives.php', []],
    'nova'             => ['initiative.php', ['id' => 0]],
    'ficha-resumo'     => ['initiative.php', ['id' => $a]],
    'ficha-tarefas'    => ['initiative.php', ['id' => $a, 'tab' => 'tasks']],
    'ficha-deps'       => ['initiative.php', ['id' => $a, 'tab' => 'deps']],
    'ficha-custos'     => ['initiative.php', ['id' => $a, 'tab' => 'costs']],
    'ficha-beneficios' => ['initiative.php', ['id' => $a, 'tab' => 'benefits']],
    'ficha-riscos'     => ['initiative.php', ['id' => $a, 'tab' => 'risks']],
    'ficha-baseline'   => ['initiative.php', ['id' => $a, 'tab' => 'baseline']],
    'ficha-org'        => ['initiative.php', ['id' => $a, 'tab' => 'org']],
    'ficha-historico'  => ['initiative.php', ['id' => $a, 'tab' => 'history']],
    'ficha-analise'    => ['initiative.php', ['id' => $b]],
    'ficha-mensuracao' => ['initiative.php', ['id' => $c]],
    'beneficios'       => ['benefits.php', []],
    'beneficio'        => ['benefit.php', ['id' => $bf]],
    'beneficio-nf'     => ['benefit.php', ['id' => $bn]],
    'beneficio-novo'   => ['benefit.php', ['id' => 0]],
    'indicadores'      => ['indicators.php', []],
    'riscos'           => ['risks.php', []],
    'relatorios'       => ['reports.php', []],
    'avisos'           => ['inbox.php', []],
    'config'           => ['config.php', []],
];
@mkdir($dir, 0755, true);
if ($only === null) {
    // um processo por tela: o cabeçalho do GLPI só é impresso uma vez por processo
    $DB->delete('glpi_users_sessions', ['login_session_uid' => $uid]);
    foreach (array_keys($shots) as $name) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --only=' . escapeshellarg($name));
    }
    echo "Instantâneos em $dir\n";
    exit(0);
}
foreach ($shots as $name => [$file, $get]) {
    if ($name !== $only) {
        continue;
    }
    $_GET = $get;
    ob_start();
    try {
        include $root . '/plugins/vmo/front/' . $file;
    } catch (Throwable $e) {
        echo "ERRO: " . $e->getMessage();
    }
    $html = (string) ob_get_clean();
    $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html); // sem JS: nada tenta validar a sessão
    // mede a altura do conteúdo (lida com --dump-dom) para o print ter o tamanho certo
    $html = str_replace('</body>', "<script>addEventListener('load',function(){var m=0;document.querySelectorAll('.page-body *').forEach(function(e){if(e.children.length===0){var r=e.getBoundingClientRect();if(r.height>0&&r.height<400)m=Math.max(m,r.bottom+scrollY)}});document.documentElement.setAttribute('data-h',Math.ceil(m)+60)})</script></body>", $html);
    file_put_contents("$dir/$name.html", $html);
    echo str_contains($html, 'ERRO:') ? "ERRO   $name\n" : "ok     $name (" . strlen($html) . " bytes)\n";
}
$DB->delete('glpi_users_sessions', ['login_session_uid' => $uid]);
echo "Instantâneos em $dir\n";
