<?php

/**
 * ITHub · Ingreso único — plugin de GLPI de INTELLIHELP.
 *
 * El personal de Intellihelp entra a la Mesa de Ayuda con la sesión única de ITHub
 * (OpenID Connect: código + PKCE, id_token RS256 validado entero). Los usuarios de los
 * clientes no ven ningún cambio: siguen entrando con su usuario y contraseña de GLPI.
 *
 * Es un plugin y no un cambio en GLPI a propósito: el núcleo se pisa en cada actualización.
 * No modifica ningún archivo de GLPI ni su configuración en la base (ver inc/glpi.php).
 *
 * Páginas (públicas, cada una valida lo suyo):
 *   front/login.php     arranca el ingreso: manda a /oauth/authorize de ITHub
 *   front/callback.php  vuelta de ITHub: valida, aplica el perfil del rol y abre la sesión
 *   front/logout.php    aviso de cierre de ITHub (back-channel logout, servidor a servidor)
 */

use Glpi\Http\Firewall;
use Glpi\Plugin\Hooks;

define('PLUGIN_ITHUB_VERSION', '1.0.0');

require_once __DIR__ . '/inc/oidc.php';
require_once __DIR__ . '/inc/glpi.php';

function plugin_version_ithub()
{
    return [
        'name'         => 'ITHub · Ingreso único',
        'version'      => PLUGIN_ITHUB_VERSION,
        'author'       => 'INTELLIHELP',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/bsierra4262/glpi-ithub',
        'requirements' => [
            'glpi' => ['min' => '12.0.0', 'max' => '12.0.99'],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_ithub_check_prerequisites()
{
    return extension_loaded('openssl') && extension_loaded('curl');
}

function plugin_ithub_check_config($verbose = false)
{
    return true;
}

function plugin_init_ithub()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['ithub'] = true;

    // Las tres páginas se usan sin sesión de GLPI: el ingreso todavía no la tiene y el aviso
    // de cierre lo manda el servidor de ITHub. Lo que las protege es state/nonce/PKCE y la
    // firma de los tokens, no la sesión.
    foreach (['login', 'callback', 'logout'] as $pagina) {
        Firewall::addPluginStrategyForLegacyScripts('ithub', '#^/front/' . $pagina . '\.php$#', Firewall::STRATEGY_NO_CHECK);
    }

    if (!ithub_habilitado()) {
        return;
    }

    // Botón «Ingresar con ITHub» en la pantalla de login (el formulario de siempre queda igual).
    $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['ithub'] = 'plugin_ithub_boton_login';

    // «Salir» de una sesión abierta por ITHub cierra también la sesión única.
    ithub_preparar_salida();
}

/** Botón de la pantalla de login. */
function plugin_ithub_boton_login()
{
    $url = htmlspecialchars(ithub_url('front/login.php'), ENT_QUOTES);
    echo '<div class="ithub-ingreso mb-3">'
       . '<a class="btn btn-primary w-100" href="' . $url . '">'
       . '<i class="ti ti-login-2 me-2"></i>Ingresar con ITHub</a>'
       . '<div class="text-muted small mt-2 text-center">Personal de Intellihelp: sesión única de ITHub.</div>'
       . '</div>';
}
