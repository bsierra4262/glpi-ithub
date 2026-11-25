<?php
// Prueba del lado GLPI, en el servidor, con una cuenta temporal que se borra al final.
// No usa ITHub ni tokens: comprueba la creación de la cuenta, el cambio de perfil, la apertura de
// la sesión por la autenticación externa sólo en memoria y que REMOTE_USER solo no hace entrar.
//
//   php test/glpi-servidor.php   (con el plugin copiado en GLPI/plugins/ithub)
chdir(getenv('HOME') . '/domains/intellihelp.tech/public_html/GLPI');
ob_start();   // GLPI abre la sesión: nada puede salir antes
require 'vendor/autoload.php';
$GLOBALS['kernel'] = $k = new Glpi\Kernel\Kernel('production');
$k->boot();
$rp = new ReflectionProperty($k, 'main_request');
$rp->setValue($k, Symfony\Component\HttpFoundation\Request::create(
    'https://ayuda.intellihelp.tech/plugins/ithub/front/callback.php', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
require_once 'plugins/ithub/inc/oidc.php';   // si el plugin está activo, ya lo cargó GLPI
require_once 'plugins/ithub/inc/glpi.php';
global $DB, $CFG_GLPI;
$email = 'ithub-prueba-temporal@intellihelp.tech';
$prev = new User();
if ($prev->getFromDBbyName($email)) {
    $prev->delete(['id' => $prev->getID()], true);
    echo "resto previo borrado\n";
}
$GLOBALS['ITHUB_CFG'] = ['client_id' => 'ayuda', 'client_secret' => 'x', 'entidad' => 16, 'recursiva' => false];
$listar = static function (int $id) use ($DB): string {
    $out = [];
    foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $id]]) as $r) {
        $out[] = [$r['profiles_id'], $r['entities_id'], $r['is_recursive']];
    }
    return json_encode($out);
};
try {
    $u = ithub_crear_usuario($email, ['name' => 'Prueba Temporal']);
    echo 'creado: id=', $u['id'], ' name=', $u['name'], ' authtype=', $u['authtype'], ' pass_vacia=', empty($u['password']) ? 'si' : 'no', "\n";
    echo 'buscar: ', ithub_buscar_usuario($email)['estado'], "\n";
    ithub_aplicar_perfil((int) $u['id'], 1);
    echo 'perfiles tras autoservicio: ', $listar((int) $u['id']), "\n";
    ithub_aplicar_perfil((int) $u['id'], 6);
    $uu = new User();
    $uu->getFromDB($u['id']);
    echo 'perfiles tras tecnico: ', $listar((int) $u['id']), ' default=', $uu->fields['profiles_id'], "\n";

    $_SERVER['REMOTE_USER'] = 'admin';   // un REMOTE_USER previo no tiene que importar
    $ok = ithub_abrir_sesion_glpi($u['name'], (int) $u['id']);
    echo 'abrir sesion: ', $ok ? 'ok' : 'falla', ' glpiID=', var_export(Session::getLoginUserID(), true),
        ' extauth=', $_SESSION['glpiextauth'] ?? '-', ' remote_user_en_sesion=', isset($_SESSION['glpi_remote_user']) ? 'si' : 'no',
        ' perfil=', $_SESSION['glpiactiveprofile']['name'] ?? '-', "\n";
    echo 'config restaurada: ssovariables_id=', $CFG_GLPI['ssovariables_id'], ' REMOTE_USER=', $_SERVER['REMOTE_USER'] ?? '-', "\n";
    Session::destroy();
    $ok2 = ithub_abrir_sesion_glpi('admin', 999999);
    echo 'con id distinto al esperado: ', $ok2 ? 'ENTRA (MAL)' : 'rechazado', ' glpiID=', var_export(Session::getLoginUserID(), true), "\n";
    Session::destroy();
    // Sin el plugin, un REMOTE_USER no hace entrar a nadie (ssovariables_id = 0 en la base).
    $_SERVER['REMOTE_USER'] = 'admin';
    $a = new Auth();
    echo 'auth externa sin el plugin: ', var_export(Auth::checkAlternateAuthSystems(), true), "\n";
} finally {
    $x = new User();
    if ($x->getFromDBbyName($email)) {
        $id = $x->getID();
        $x->delete(['id' => $id], true);
        echo 'borrado: ', countElementsInTable('glpi_users', ['id' => $id]), ' usuario, ',
            countElementsInTable('glpi_profiles_users', ['users_id' => $id]), ' perfiles, ',
            countElementsInTable('glpi_useremails', ['users_id' => $id]), " emails\n";
    }
}
file_put_contents("php://stderr", ob_get_clean());
