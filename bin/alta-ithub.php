<?php

/**
 * Alta de la Mesa de Ayuda (GLPI) en ITHub y configuración del plugin. Corre en el servidor:
 *
 *   php bin/alta-ithub.php            muestra qué haría, sin cambiar nada
 *   php bin/alta-ithub.php --aplicar  lo hace
 *
 * Qué hace con --aplicar:
 *   1. En ITHub (sólo filas de su base, con sus funciones): roles de la app `ayuda` iguales a
 *      los del plugin, dirección de la tarjeta, URIs de vuelta / después de salir / aviso de
 *      cierre, y un secreto nuevo (ITHub guarda sólo su SHA-256).
 *   2. Guarda el secreto en GLPI/config/ithub-secreto-ayuda.txt (600) y escribe
 *      GLPI/config/ithub.php (600). Si ya existían, quedan respaldos *.antes-ithub-<fecha>.
 *   3. Da permiso en ITHub al personal que hoy usa GLPI, con el rol equivalente a su perfil
 *      (crea su usuario de ITHub si no existe). Nadie pierde el acceso.
 *
 * Nunca imprime el secreto.
 */

$aplicar = in_array('--aplicar', $argv, true);
$home = getenv('HOME');
$glpi = "$home/domains/intellihelp.tech/public_html/GLPI";
$base = 'https://ayuda.intellihelp.tech';

$_SERVER['SCRIPT_NAME'] = '/index.php';
require "$home/domains/ithub.intellihelp.tech/public_html/app/bootstrap.php";

// Roles de ITHub para la Mesa de Ayuda, de mayor a menor (= ITHUB_PERFILES_DEFECTO del plugin).
$roles = ['admin', 'mesa', 'tecnico', 'autoservicio'];

// Personal de Intellihelp con cuenta en GLPI y su rol (perfil actual en GLPI → rol).
// bsierra es super admin de ITHub: se le pone explícito el rol que tiene hoy en GLPI.
$personal = [
    'bsierra@intellihelp.tech'  => ['Brian Sierra', 'mesa'],      // Crear y Asignar Tickets
    'jayala@intellihelp.tech'   => ['Alejandro Ayala', 'admin'],  // Admin
    'lzaltron@intellihelp.tech' => ['Laura Zaltron', 'mesa'],     // Crear y Asignar Tickets
    'lcampos@intellihelp.tech'  => ['Lisandro Campos', 'mesa'],   // Crear y Asignar Tickets
];

$app = app_por_clave('ayuda');
if ($app === null) {
    fwrite(STDERR, "no existe la app ayuda en ITHub\n");
    exit(1);
}
$previos = q('SELECT rol, COUNT(*) n FROM permisos WHERE aplicacion_id = ? GROUP BY rol', [$app['id']]);
echo 'permisos actuales en ayuda: ', json_encode($previos), "\n";
foreach ($previos as $p) {
    if (!in_array($p['rol'], $roles, true)) {
        fwrite(STDERR, "hay permisos con el rol {$p['rol']}, que deja de existir: revisar antes de aplicar\n");
        exit(1);
    }
}

$cambios = [
    'roles'           => json_encode($roles),
    'rol_superadmin'  => 'admin',
    'url'             => "$base/plugins/ithub/front/login.php",
    'redirect_uris'   => "$base/plugins/ithub/front/callback.php",
    'logout_uris'     => "$base/",
    'backchannel_uri' => "$base/plugins/ithub/front/logout.php",
];
echo "app ayuda en ITHub:\n";
foreach ($cambios as $k => $v) {
    echo "  $k: ", is_array($app[$k] ?? null) ? json_encode($app[$k]) : var_export($app[$k] ?? null, true), " → $v\n";
}
foreach ($personal as $email => [$nombre, $rol]) {
    $u = usuario_por_email($email);
    echo "  permiso $email: ", $u ? 'usuario ' . $u['id'] : 'usuario nuevo', " → $rol\n";
}
if (!$aplicar) {
    echo "(en seco: no se cambió nada; correr con --aplicar)\n";
    exit(0);
}

$ts = date('Ymd-His');
qx('UPDATE aplicaciones SET roles = ?, rol_superadmin = ?, url = ?, redirect_uris = ?, logout_uris = ?, backchannel_uri = ?, actualizado_en = ? WHERE id = ?', [
    $cambios['roles'], $cambios['rol_superadmin'], $cambios['url'], $cambios['redirect_uris'],
    $cambios['logout_uris'], $cambios['backchannel_uri'], ahora(), $app['id'],
]);
auditar(null, 'aplicacion_editada', $app['id'], 'ayuda', ['origen' => 'ingreso único de GLPI (consola)', 'roles' => $roles]);

$secreto = oidc_generar_secreto($app['id']);
auditar(null, 'secreto_generado', $app['id'], 'ayuda', ['origen' => 'ingreso único de GLPI (consola)']);

$archSecreto = "$glpi/config/ithub-secreto-ayuda.txt";
$archCfg = "$glpi/config/ithub.php";
foreach ([$archSecreto, $archCfg] as $f) {
    if (is_file($f)) {
        copy($f, "$f.antes-ithub-$ts");
        chmod("$f.antes-ithub-$ts", 0600);
    }
}
file_put_contents($archSecreto, $secreto);
chmod($archSecreto, 0600);
$cfg = "<?php\n\n"
    . "// Ingreso con ITHub (plugin glpi-ithub). Fuera del webroot; NO se versiona.\n"
    . "// Ejemplo y explicación de cada clave: config/ithub.example.php del repo glpi-ithub.\n"
    . "return [\n"
    . "    'issuer'        => 'https://ithub.intellihelp.tech',\n"
    . "    'client_id'     => 'ayuda',\n"
    . "    'client_secret' => " . var_export($secreto, true) . ",\n"
    . "];\n";
file_put_contents($archCfg, $cfg);
chmod($archCfg, 0600);

$sistema = (int) (q1('SELECT id FROM usuarios WHERE email = ?', ['bsierra@intellihelp.tech'])['id'] ?? 0);
foreach ($personal as $email => [$nombre, $rol]) {
    $u = usuario_por_email($email);
    $id = $u ? (int) $u['id'] : crear_usuario($email, $nombre, 'usuario');
    guardar_permisos($id, $email, [$app['id'] => $rol], $sistema);
}

$c = include $archCfg;
$a = app_por_clave('ayuda');
echo 'config del plugin: ', ($c['client_secret'] ?? '') === $secreto ? 'ok' : 'FALLA', "\n";
echo 'ayuda en ITHub: sso=', $a['sso'] ? 'si' : 'no', ' · roles=', json_encode($a['roles']), ' · vuelta=', count($a['redirect_uris_lista']),
    ' · aviso=', $a['backchannel_uri'], ' · tarjeta=', $a['url'], "\n";
foreach ($personal as $email => $x) {
    echo "  $email → ", rol_en_app(usuario_por_email($email), $a) ?? 'SIN ACCESO', "\n";
}
