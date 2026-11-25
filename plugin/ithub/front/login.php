<?php

/**
 * Arranque del ingreso con ITHub (GET /plugins/ithub/front/login.php).
 *
 * Es la dirección de la tarjeta «Mesa de Ayuda» en ITHub. Guarda state, nonce y el verificador
 * de PKCE en la sesión (anónima) de GLPI y manda a /oauth/authorize. Si ya hay una sesión de
 * GLPI abierta, entra directo.
 */

use Symfony\Component\HttpFoundation\RedirectResponse;

if (!ithub_habilitado()) {
    return ithub_pagina_error('El ingreso con ITHub no está configurado en esta instalación.', 503);
}

$destino = ithub_destino_seguro((string) ($_GET['redirect'] ?? ''));
if (Session::getLoginUserID()) {
    Auth::redirectIfAuthenticated($destino !== '' ? $destino : null);
}

[$url, $estado] = ithub_url_autorizacion();
$_SESSION['ithub_pedido'] = $estado + ['destino' => $destino, 'creado' => time()];

return new RedirectResponse($url, 302, ['Cache-Control' => 'no-store']);
