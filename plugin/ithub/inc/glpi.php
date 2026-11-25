<?php

declare(strict_types=1);

/**
 * La parte que toca GLPI: buscar o crear la cuenta, aplicar el perfil del rol, abrir la sesión
 * por el camino de autenticación externa de GLPI y registrar el `sid` de ITHub para el aviso
 * de cierre.
 *
 * CÓMO SE ABRE LA SESIÓN SIN ABRIR UNA PUERTA. GLPI trae un SSO por variable de servidor
 * (Configuración → Autenticación → Otros métodos: `ssovariables_id`, p. ej. REMOTE_USER):
 * si la variable viene, la persona entra sin contraseña. Activarlo en la configuración haría
 * que GLPI confíe en esa variable en TODOS los pedidos, y la seguridad dependería de que nadie
 * pueda hacerla llegar. Acá no se activa: `ssovariables_id` queda en 0 en la base (GLPI nunca
 * mira REMOTE_USER) y el plugin lo prende sólo en memoria, dentro de este pedido, después de
 * haber validado el id_token, y lo apaga apenas termina el login. No hay cabecera, cookie ni
 * variable que alguien pueda mandar para hacerse pasar por otro.
 */

use Glpi\DBAL\QuerySubQuery;
use Glpi\Security\SessionTracker;

/** Tabla del plugin: sid de ITHub → sesión de GLPI (login_session_uid). */
const ITHUB_TABLA_SESIONES = 'glpi_plugin_ithub_sessions';

/**
 * Cuenta de GLPI de un email. Sólo cuentas no borradas y sin ambigüedad: si dos cuentas
 * comparten el email no se elige ninguna.
 *
 * @return array{estado:string,usuario:?array} estado: ok | no_existe | ambiguo
 */
function ithub_buscar_usuario(string $email): array
{
    global $DB;

    $ids = [];
    foreach ($DB->request([
        'SELECT' => ['users_id'],
        'FROM'   => 'glpi_useremails',
        'WHERE'  => ['email' => $email],
    ]) as $r) {
        $ids[(int) $r['users_id']] = true;
    }
    // Un usuario creado por este plugin tiene el email como nombre.
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => $email]]) as $r) {
        $ids[(int) $r['id']] = true;
    }
    $usuarios = [];
    if ($ids !== []) {
        foreach ($DB->request([
            'FROM'  => 'glpi_users',
            'WHERE' => ['id' => array_keys($ids), 'is_deleted' => 0],
        ]) as $u) {
            $usuarios[] = $u;
        }
    }
    if (count($usuarios) === 0) {
        return ['estado' => 'no_existe', 'usuario' => null];
    }
    if (count($usuarios) > 1) {
        return ['estado' => 'ambiguo', 'usuario' => null];
    }
    return ['estado' => 'ok', 'usuario' => $usuarios[0]];
}

/** id del perfil de GLPI con ese nombre (0 si no existe). */
function ithub_perfil_id(string $nombre): int
{
    global $DB;
    $r = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles', 'WHERE' => ['name' => $nombre], 'LIMIT' => 1])->current();
    return (int) ($r['id'] ?? 0);
}

/** ids de los perfiles del mapeo rol → perfil (los que el plugin administra). */
function ithub_perfiles_gestionados(): array
{
    $ids = [];
    foreach (ithub_cfg()['perfiles'] as $nombre) {
        $id = ithub_perfil_id((string) $nombre);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Crea la cuenta de GLPI de alguien del personal que entra por primera vez. Nombre de ingreso
 * = el email (no choca con las cuentas cortas de los clientes), autenticación externa, sin
 * contraseña.
 */
function ithub_crear_usuario(string $email, array $claims): ?array
{
    $nombre = trim((string) ($claims['name'] ?? ''));
    $partes = $nombre !== '' ? preg_split('/\s+/', $nombre, 2) : [];
    $user = new User();
    $id = $user->add([
        'name'        => $email,
        'firstname'   => $partes[0] ?? '',
        'realname'    => $partes[1] ?? '',
        'authtype'    => Auth::EXTERNAL,
        'auths_id'    => 0,
        'is_active'   => 1,
        '_extauth'    => 1,
        '_useremails' => [-1 => $email],
        '_default_email' => -1,
        'comment'     => 'Creado por el ingreso con ITHub.',
    ]);
    if (!$id) {
        return null;
    }
    $user->getFromDB($id);
    return $user->fields;
}

/**
 * Deja las habilitaciones de la persona con el perfil que corresponde a su rol de ITHub
 * (ver ithub_plan_perfiles) y ese perfil como el de inicio.
 */
function ithub_aplicar_perfil(int $usuarioId, int $destino): void
{
    global $DB;

    $filas = [];
    foreach ($DB->request([
        'SELECT' => ['id', 'profiles_id', 'entities_id', 'is_recursive', 'is_dynamic'],
        'FROM'   => 'glpi_profiles_users',
        'WHERE'  => ['users_id' => $usuarioId],
    ]) as $r) {
        $filas[] = $r;
    }
    $cfg = ithub_cfg();
    $plan = ithub_plan_perfiles($filas, $destino, ithub_perfiles_gestionados(), $cfg['entidad'], $cfg['recursiva']);

    $porId = array_column($filas, null, 'id');
    $pu = new Profile_User();
    // Una habilitación no cambia de perfil (GLPI no deja mover una relación): se agrega la
    // nueva en la misma entidad y recién después se borra la vieja, así nunca queda sin perfil.
    foreach ($plan['cambiar'] as $id) {
        $f = $porId[$id];
        if ($pu->add([
            'users_id'     => $usuarioId,
            'profiles_id'  => $destino,
            'entities_id'  => (int) $f['entities_id'],
            'is_recursive' => (int) $f['is_recursive'],
            'is_dynamic'   => 0,
        ])) {
            $pu->delete(['id' => $id], true);
        }
    }
    foreach ($plan['borrar'] as $id) {
        $pu->delete(['id' => $id], true);
    }
    if ($plan['agregar'] !== null) {
        $pu->add([
            'users_id'     => $usuarioId,
            'profiles_id'  => $destino,
            'entities_id'  => $plan['agregar']['entities_id'],
            'is_recursive' => $plan['agregar']['is_recursive'],
            'is_dynamic'   => 0,
        ]);
    }

    $u = new User();
    if ($u->getFromDB($usuarioId) && (int) $u->fields['profiles_id'] !== $destino) {
        $ent = $DB->request([
            'SELECT' => ['entities_id'],
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => ['users_id' => $usuarioId, 'profiles_id' => $destino],
            'LIMIT'  => 1,
        ])->current();
        $u->update(['id' => $usuarioId, 'profiles_id' => $destino, 'entities_id' => (int) ($ent['entities_id'] ?? 0)]);
    }
}

/**
 * Abre la sesión de GLPI de la cuenta `$login` por la autenticación externa, sólo dentro de
 * este pedido (ver la cabecera del archivo). Devuelve true si GLPI dejó entrar a ESA cuenta.
 */
function ithub_abrir_sesion_glpi(string $login, int $usuarioId): bool
{
    global $CFG_GLPI, $DB;

    $var = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_ssovariables', 'WHERE' => ['name' => 'REMOTE_USER'], 'LIMIT' => 1])->current();
    if (empty($var['id'])) {
        return false;
    }

    // Lo que se cambia en memoria, para devolverlo tal cual.
    $antes = ['ssovariables_id' => $CFG_GLPI['ssovariables_id'] ?? 0,
              'existing_auth_server_field_clean_domain' => $CFG_GLPI['existing_auth_server_field_clean_domain'] ?? 0];
    foreach ($CFG_GLPI as $k => $v) {
        if (is_string($k) && str_ends_with($k, '_ssofield')) {
            $antes[$k] = $v;
            $CFG_GLPI[$k] = '';   // que GLPI no pise nombre ni emails con variables vacías
        }
    }
    $remoteAntes = $_SERVER['REMOTE_USER'] ?? null;

    $CFG_GLPI['ssovariables_id'] = (int) $var['id'];
    $CFG_GLPI['existing_auth_server_field_clean_domain'] = 0;
    $_SERVER['REMOTE_USER'] = $login;
    unset($_GET['noAUTO'], $_POST['noAUTO'], $_REQUEST['noAUTO']);

    try {
        $auth = new Auth();
        $ok = $auth->login('', '', false, false, '');
    } finally {
        foreach ($antes as $k => $v) {
            $CFG_GLPI[$k] = $v;
        }
        if ($remoteAntes === null) {
            unset($_SERVER['REMOTE_USER']);
        } else {
            $_SERVER['REMOTE_USER'] = $remoteAntes;
        }
        // Con esta marca GLPI exige la variable en cada pedido siguiente; la sesión la controla
        // el aviso de cierre de ITHub, no una variable del servidor.
        unset($_SESSION['glpi_remote_user']);
    }

    if (!$ok || (int) Session::getLoginUserID() !== $usuarioId) {
        if (Session::getLoginUserID()) {
            Session::cleanOnLogout();
        }
        return false;
    }
    return true;
}

/** Guarda sid/sub de ITHub ↔ sesión de GLPI, para cerrarla cuando ITHub avise. */
function ithub_registrar_sesion(array $claims, int $usuarioId): void
{
    global $DB;

    $uid = (string) (Session::getLoginSessionUID() ?? '');
    if ($uid === '') {
        return;
    }
    $DB->insert(ITHUB_TABLA_SESIONES, [
        'sid'               => substr((string) ($claims['sid'] ?? ''), 0, 64),
        'sub'               => substr((string) ($claims['sub'] ?? ''), 0, 64),
        'login_session_uid' => $uid,
        'users_id'          => $usuarioId,
        'date_creation'     => date('Y-m-d H:i:s'),
    ]);
    // Limpieza: las filas de sesiones de GLPI que ya no existen no sirven.
    $DB->delete(ITHUB_TABLA_SESIONES, [
        'date_creation' => ['<', date('Y-m-d H:i:s', time() - 86400)],
        'NOT' => ['login_session_uid' => new QuerySubQuery(['SELECT' => 'login_session_uid', 'FROM' => 'glpi_users_sessions'])],
    ]);
}

/**
 * Cierra las sesiones de GLPI abiertas con ese sid de ITHub (o, si el aviso trae sólo `sub`,
 * todas las de esa persona abiertas por ITHub). Usa el registro de sesiones de GLPI: la
 * sesión queda revocada y su archivo borrado, igual que «Cerrar sesión» desde Mis sesiones.
 *
 * @return int cuántas se cerraron
 */
function ithub_revocar(string $sid, string $sub): int
{
    global $DB;

    $where = $sid !== '' ? ['sid' => $sid] : ['sub' => $sub];
    if ($sid === '' && $sub === '') {
        return 0;
    }
    $n = 0;
    foreach ($DB->request(['SELECT' => ['id', 'login_session_uid'], 'FROM' => ITHUB_TABLA_SESIONES, 'WHERE' => $where]) as $r) {
        SessionTracker::revokeSession((string) $r['login_session_uid'], SessionTracker::REVOKE_REASON_USER);
        $DB->delete(ITHUB_TABLA_SESIONES, ['id' => (int) $r['id']]);
        $n++;
    }
    return $n;
}

/**
 * Salida manual: si la sesión entró por ITHub, «Salir» de GLPI cierra también la sesión única.
 *
 * front/logout.php de GLPI manda a `ssologout_url` cuando hay SSO configurado. Igual que en el
 * ingreso, se configura sólo en memoria y sólo para este pedido de salida: las cuentas que no
 * entraron por ITHub (clientes, admin local) salen como siempre.
 */
function ithub_preparar_salida(): void
{
    global $CFG_GLPI;

    $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!str_ends_with($ruta, '/front/logout.php') || empty($_SESSION['ithub']['id_token'])) {
        return;
    }
    $CFG_GLPI['ssovariables_id'] = 2;   // sólo tiene que ser > 0 para que logout.php use la URL
    $CFG_GLPI['ssologout_url'] = ithub_url_salida((string) $_SESSION['ithub']['id_token'], ithub_cfg()['url_base'] . '/');
}

/** Registro en el log del plugin (files/_log/ithub.log). Nunca tokens ni secretos. */
function ithub_log(string $mensaje): void
{
    if (class_exists('Toolbox')) {
        Toolbox::logInFile('ithub', $mensaje . "\n");
    }
}

/**
 * Pantalla corta para cuando el ingreso no se puede completar: el motivo (sin detalles
 * técnicos), un botón para volver a probar y el login de siempre.
 */
function ithub_pagina_error(string $mensaje, int $codigo = 403): Symfony\Component\HttpFoundation\Response
{
    $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
    $base = ithub_cfg()['url_base'];
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Mesa de Ayuda · Ingreso con ITHub</title></head>'
        . '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:4rem auto;padding:0 1rem;color:#1f2937">'
        . '<h1 style="font-size:1.25rem">No se pudo ingresar con ITHub</h1>'
        . '<p>' . $e($mensaje) . '</p>'
        . '<p><a href="' . $e(ithub_url('front/login.php')) . '">Volver a intentar</a> · '
        . '<a href="' . $e($base . '/?noAUTO=1') . '">Ingresar con usuario y contraseña</a></p>'
        . '</body></html>';
    return new Symfony\Component\HttpFoundation\Response($html, $codigo, [
        'Content-Type'  => 'text/html; charset=utf-8',
        'Cache-Control' => 'no-store',
    ]);
}
