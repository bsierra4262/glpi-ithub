<?php

/**
 * Vuelta de ITHub después del ingreso (GET /plugins/ithub/front/callback.php).
 *
 * 1. Valida el state contra la sesión y que el pedido no tenga más de 10 minutos.
 * 2. Canjea el código por los tokens, servidor a servidor, con el secreto y el verificador PKCE.
 * 3. Valida el id_token entero: RS256 con la clave de /oauth/jwks, iss, aud, exp y nonce.
 * 4. Exige email verificado de un dominio del personal y un rol conocido.
 * 5. Busca la cuenta de GLPI por email (o la crea), le deja el perfil del rol y abre la sesión
 *    por la autenticación externa de GLPI, sólo en este pedido (ver inc/glpi.php).
 * 6. Guarda sid/sub de ITHub para el aviso de cierre y el id_token para la salida.
 *
 * Si ITHub contesta access_denied es que la persona no tiene la Mesa de Ayuda habilitada.
 */

if (!ithub_habilitado()) {
    return ithub_pagina_error('El ingreso con ITHub no está configurado en esta instalación.', 503);
}

$pedido = (array) ($_SESSION['ithub_pedido'] ?? []);
unset($_SESSION['ithub_pedido']);   // un solo uso, salga bien o mal

$state = (string) ($_GET['state'] ?? '');
if ($state === '' || empty($pedido['state']) || !hash_equals((string) $pedido['state'], $state)
    || (int) ($pedido['creado'] ?? 0) < time() - 600) {
    return ithub_pagina_error('La validación de seguridad del ingreso falló o venció. Volvé a intentar.', 400);
}
if (isset($_GET['error'])) {
    return ($_GET['error'] ?? '') === 'access_denied'
        ? ithub_pagina_error('Tu usuario de ITHub no tiene acceso a la Mesa de Ayuda. Si lo necesitás, pedíselo a un administrador de ITHub.')
        : ithub_pagina_error('ITHub no completó el ingreso.', 400);
}

$tok = ithub_canjear_codigo((string) ($_GET['code'] ?? ''), (string) ($pedido['ver'] ?? ''));
try {
    $claims = ithub_validar_jwt((string) ($tok['id_token'] ?? ''), (string) ($pedido['nonce'] ?? ''));
} catch (Throwable $e) {
    ithub_log('id_token rechazado: ' . $e->getMessage() . (isset($tok['error']) ? ' (' . $tok['error'] . ')' : ''));
    return ithub_pagina_error('No se pudo validar el ingreso con ITHub. Volvé a intentar.', 400);
}

$email = ithub_email_permitido($claims);
$rol = (string) ($claims['rol'] ?? '');
$perfilNombre = ithub_perfil_para_rol($rol);
$perfilId = $perfilNombre !== null ? ithub_perfil_id($perfilNombre) : 0;
if ($email === null) {
    ithub_log('email no permitido para ' . ($claims['sub'] ?? '?'));
    return ithub_pagina_error('El ingreso con ITHub es sólo para cuentas del personal de Intellihelp.');
}
if ($perfilId === 0) {
    ithub_log("$email: rol de ITHub sin perfil de GLPI: $rol");
    return ithub_pagina_error('Tu rol en ITHub no corresponde a ningún perfil de la Mesa de Ayuda. Avisale a un administrador.');
}

$busca = ithub_buscar_usuario($email);
if ($busca['estado'] === 'ambiguo') {
    ithub_log("$email: hay más de una cuenta de GLPI con ese email");
    return ithub_pagina_error('Hay más de una cuenta de la Mesa de Ayuda con tu email. Avisale a un administrador.');
}
$usuario = $busca['usuario'] ?? ithub_crear_usuario($email, $claims);
if ($usuario === null) {
    ithub_log("$email: no se pudo crear la cuenta de GLPI");
    return ithub_pagina_error('No se pudo crear tu cuenta en la Mesa de Ayuda. Avisale a un administrador.', 500);
}
if ((int) $usuario['is_active'] !== 1) {
    ithub_log("$email: la cuenta de GLPI {$usuario['name']} está desactivada");
    return ithub_pagina_error('Tu cuenta de la Mesa de Ayuda está desactivada. Avisale a un administrador.');
}

ithub_aplicar_perfil((int) $usuario['id'], $perfilId);

if (!ithub_abrir_sesion_glpi((string) $usuario['name'], (int) $usuario['id'])) {
    ithub_log("$email: GLPI no abrió la sesión de {$usuario['name']}");
    return ithub_pagina_error('La Mesa de Ayuda no pudo abrir tu sesión. Avisale a un administrador.', 500);
}

// La sesión es nueva (GLPI la regeneró al entrar): recién ahora se guarda lo de ITHub.
$_SESSION['ithub'] = ['sid' => (string) ($claims['sid'] ?? ''), 'id_token' => (string) $tok['id_token']];
ithub_registrar_sesion($claims, (int) $usuario['id']);
ithub_log("$email entró como {$usuario['name']} (rol $rol → $perfilNombre)");

$destino = (string) ($pedido['destino'] ?? '');
Auth::redirectIfAuthenticated($destino !== '' ? $destino : null);
Html::redirect(ithub_cfg()['url_base'] . '/');
