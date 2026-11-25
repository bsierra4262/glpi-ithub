<?php

/**
 * Aviso de cierre de ITHub (OpenID Connect Back-Channel Logout 1.0).
 *
 * POST /plugins/ithub/front/logout.php con `logout_token`. Lo llama el servidor de ITHub, no
 * un navegador: no hay cookie ni CSRF que validar; lo que se valida es la firma del token
 * (RS256), iss, aud, exp, el evento de back-channel y que no traiga nonce. Cierra toda sesión
 * de GLPI abierta con ese sid (o de esa persona, si sólo trae sub).
 *
 * 200 si se procesó, 400 si el token no es válido (lo pide el estándar).
 */

use Symfony\Component\HttpFoundation\Response;

$sinCache = ['Cache-Control' => 'no-store'];
if (!ithub_habilitado() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    return new Response('', 400, $sinCache);
}

try {
    $c = ithub_validar_jwt((string) ($_POST['logout_token'] ?? ''), null, true);
} catch (Throwable $e) {
    ithub_log('logout_token rechazado: ' . $e->getMessage());
    return new Response('', 400, $sinCache);
}

$n = ithub_revocar((string) ($c['sid'] ?? ''), (string) ($c['sub'] ?? ''));
ithub_log("ITHub avisó el cierre de una sesión única: $n sesión(es) de GLPI cerrada(s)");
return new Response('', 200, $sinCache);
