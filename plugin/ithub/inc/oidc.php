<?php

declare(strict_types=1);

/**
 * Cliente OpenID Connect de ITHub, sin dependencias de GLPI.
 *
 * Todo lo que no toca la base ni la sesión de GLPI vive acá para poder probarlo solo
 * (test/run.php): configuración, URL de autorización con state/nonce/PKCE, canje del código,
 * JWKS en caché con el PEM armado desde el JWK, validación de id_token y logout_token, y las
 * decisiones del ingreso (dominio permitido, perfil para el rol, qué cambiar en los perfiles).
 *
 * Mismo diseño que el CRM de Intellihelp (app/web/app/ithub.php). Contrato del proveedor:
 * md/INTEGRAR-APLICACION.md del repo ithub-portal.
 */

const ITHUB_EVENTO_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';
const ITHUB_JWKS_TTL = 3600;

/** Rol de ITHub → nombre del perfil de GLPI (de mayor a menor jerarquía). */
const ITHUB_PERFILES_DEFECTO = [
    'admin'        => 'Admin',
    'mesa'         => 'Crear y Asignar Tickets',
    'tecnico'      => 'Technician',
    'autoservicio' => 'Self-Service',
];

/**
 * Configuración del ingreso con ITHub.
 *
 * Sale de config/ithub.php de GLPI (fuera del webroot, no se versiona y ninguna actualización
 * de GLPI lo pisa). Los tests la inyectan en $GLOBALS['ITHUB_CFG'].
 */
function ithub_cfg(): array
{
    static $cache = null;
    if (array_key_exists('ITHUB_CFG', $GLOBALS)) {
        $c = (array) $GLOBALS['ITHUB_CFG'];
    } else {
        if ($cache === null) {
            $archivo = (defined('GLPI_CONFIG_DIR') ? GLPI_CONFIG_DIR : __DIR__ . '/../../../config') . '/ithub.php';
            $cache = is_file($archivo) ? (array) (include $archivo) : [];
        }
        $c = $cache;
    }
    $c['issuer']        = rtrim((string) ($c['issuer'] ?? 'https://ithub.intellihelp.tech'), '/');
    $c['client_id']     = (string) ($c['client_id'] ?? '');
    $c['client_secret'] = (string) ($c['client_secret'] ?? '');
    // La dirección pública de GLPI (Configuración → General); se puede fijar en la config.
    $c['url_base']      = rtrim((string) ($c['url_base'] ?? ($GLOBALS['CFG_GLPI']['url_base'] ?? '')), '/');
    $c['dominios']      = array_map('strtolower', (array) ($c['dominios'] ?? ['intellihelp.tech']));
    $c['perfiles']      = (array) ($c['perfiles'] ?? ITHUB_PERFILES_DEFECTO);
    $c['entidad']       = (int) ($c['entidad'] ?? 0);
    $c['recursiva']     = (bool) ($c['recursiva'] ?? true);
    return $c;
}

/** ¿Está configurado? Sin client_id y client_secret el plugin no hace nada. */
function ithub_habilitado(): bool
{
    $c = ithub_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** Dirección pública de una página del plugin (url_base de GLPI + /plugins/ithub/...). */
function ithub_url(string $ruta, string $urlBase = ''): string
{
    $base = $urlBase !== '' ? rtrim($urlBase, '/') : ithub_cfg()['url_base'];
    return $base . '/plugins/ithub/' . ltrim($ruta, '/');
}

/** Dirección de vuelta registrada en ITHub. */
function ithub_redirect_uri(): string
{
    return ithub_url('front/callback.php');
}

function ithub_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function ithub_b64url_dec(string $txt): string
{
    return (string) base64_decode(strtr($txt, '-_', '+/') . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
}

/**
 * Arma la dirección de /oauth/authorize. Devuelve [url, estado]: el estado (state, nonce y
 * verificador de PKCE) lo guarda quien llama en la sesión para validarlo en la vuelta.
 *
 * @return array{0:string,1:array{state:string,nonce:string,ver:string}}
 */
function ithub_url_autorizacion(): array
{
    $c = ithub_cfg();
    $estado = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'ver'   => ithub_b64url(random_bytes(48)),
    ];
    $url = $c['issuer'] . '/oauth/authorize?' . http_build_query([
        'client_id'             => $c['client_id'],
        'redirect_uri'          => ithub_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $estado['state'],
        'nonce'                 => $estado['nonce'],
        'code_challenge'        => ithub_b64url(hash('sha256', $estado['ver'], true)),
        'code_challenge_method' => 'S256',
    ]);
    return [$url, $estado];
}

/**
 * Pedido HTTP a ITHub. Devuelve [código, cuerpo decodificado]; sin conexión, [0, []].
 * Con $post se autentica con el secreto (client_secret_basic). Nunca lo deja en un log.
 */
function ithub_http(string $url, ?array $post = null, int $timeout = 10): array
{
    $c = ithub_cfg();
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ];
    if ($post !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        $opts[CURLOPT_USERPWD] = rawurlencode($c['client_id']) . ':' . rawurlencode($c['client_secret']);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$codigo, is_string($res) ? (json_decode($res, true) ?: []) : []];
}

/** Canjea el código de autorización por los tokens (con el verificador de PKCE). */
function ithub_canjear_codigo(string $code, string $verificador): array
{
    [, $r] = ithub_http(ithub_cfg()['issuer'] . '/oauth/token', [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => ithub_redirect_uri(),
        'code_verifier' => $verificador,
    ]);
    return $r;
}

// ---- Claves públicas ----

function ithub_der_largo(int $n): string
{
    if ($n < 0x80) {
        return chr($n);
    }
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}

function ithub_der(int $tag, string $valor): string
{
    return chr($tag) . ithub_der_largo(strlen($valor)) . $valor;
}

/** Entero DER positivo (se antepone un 0 si el primer bit está prendido). */
function ithub_der_entero(string $bin): string
{
    $bin = ltrim($bin, "\0");
    if ($bin === '' || ord($bin[0]) > 0x7f) {
        $bin = "\0" . $bin;
    }
    return ithub_der(0x02, $bin);
}

/** PEM de una clave pública RSA a partir del JWK (n, e en base64url). */
function ithub_jwk_a_pem(array $jwk): string
{
    $rsa = ithub_der(0x30, ithub_der_entero(ithub_b64url_dec((string) ($jwk['n'] ?? '')))
                         . ithub_der_entero(ithub_b64url_dec((string) ($jwk['e'] ?? ''))));
    $alg = ithub_der(0x30, ithub_der(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01") . "\x05\x00");
    $spki = ithub_der(0x30, $alg . ithub_der(0x03, "\0" . $rsa));
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Archivo de caché del JWKS (files/_plugins/ithub de GLPI, fuera del webroot). */
function ithub_jwks_archivo(): ?string
{
    if (!defined('GLPI_PLUGIN_DOC_DIR')) {
        return null;
    }
    $dir = GLPI_PLUGIN_DOC_DIR . '/ithub';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return is_dir($dir) ? $dir . '/jwks.json' : null;
}

/**
 * Claves de ITHub por kid. Se guardan una hora; si el kid pedido no está (ITHub rotó la
 * clave), se vuelven a bajar.
 *
 * @return array<string,array> kid → JWK
 */
function ithub_jwks(string $kid = ''): array
{
    $archivo = ithub_jwks_archivo();
    if ($archivo !== null && is_file($archivo) && filemtime($archivo) > time() - ITHUB_JWKS_TTL) {
        $claves = json_decode((string) file_get_contents($archivo), true) ?: [];
        if ($claves !== [] && ($kid === '' || isset($claves[$kid]))) {
            return $claves;
        }
    }
    [$codigo, $r] = ithub_http(ithub_cfg()['issuer'] . '/oauth/jwks');
    $claves = [];
    foreach ((array) ($codigo === 200 ? ($r['keys'] ?? []) : []) as $k) {
        if (is_array($k) && ($k['kty'] ?? '') === 'RSA' && isset($k['kid'], $k['n'], $k['e'])) {
            $claves[(string) $k['kid']] = $k;
        }
    }
    if ($claves !== [] && $archivo !== null) {
        @file_put_contents($archivo, json_encode($claves), LOCK_EX);
    }
    return $claves;
}

/**
 * Valida un JWT de ITHub (id_token o logout_token) y devuelve sus claims.
 *
 * Comprueba: RS256, firma con la clave del kid, iss, aud = nuestro client_id, vencimiento
 * (60 s de tolerancia) y, si se pasa, el nonce. Un logout_token además tiene que traer el
 * evento de back-channel logout, no puede traer nonce y necesita sid o sub.
 *
 * @param array<string,array>|null $claves para los tests; null = las de ITHub
 * @throws RuntimeException con un motivo corto (va al log, nunca a pantalla)
 */
function ithub_validar_jwt(string $jwt, ?string $nonce = null, bool $esLogout = false, ?array $claves = null): array
{
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) {
        throw new RuntimeException('formato');
    }
    [$h, $p, $s] = $partes;
    $cab = json_decode(ithub_b64url_dec($h), true);
    $c = json_decode(ithub_b64url_dec($p), true);
    if (!is_array($cab) || !is_array($c)) {
        throw new RuntimeException('formato');
    }
    if (($cab['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('alg');
    }
    $kid = (string) ($cab['kid'] ?? '');
    $claves ??= ithub_jwks($kid);
    if (!isset($claves[$kid])) {
        throw new RuntimeException('kid');
    }
    if (openssl_verify("$h.$p", ithub_b64url_dec($s), ithub_jwk_a_pem($claves[$kid]), OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('firma');
    }
    $cfg = ithub_cfg();
    $aud = $c['aud'] ?? '';
    $audOk = is_array($aud) ? in_array($cfg['client_id'], $aud, true) : $aud === $cfg['client_id'];
    if (($c['iss'] ?? '') !== $cfg['issuer'] || !$audOk || $cfg['client_id'] === '') {
        throw new RuntimeException('iss/aud');
    }
    if ((int) ($c['exp'] ?? 0) < time() - 60) {
        throw new RuntimeException('vencido');
    }
    if ($esLogout) {
        if (!isset($c['events'][ITHUB_EVENTO_LOGOUT]) || isset($c['nonce']) || (empty($c['sid']) && empty($c['sub']))) {
            throw new RuntimeException('logout_token');
        }
    } elseif ($nonce === null || $nonce === '' || !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
        throw new RuntimeException('nonce');
    }
    return $c;
}

// ---- Decisiones del ingreso ----

/**
 * Email del token si es usable para entrar: verificado y de un dominio permitido.
 * ITHub ya filtra el dominio; esto es una segunda barrera para que nunca se pueda tomar la
 * cuenta de GLPI de un cliente.
 */
function ithub_email_permitido(array $claims): ?string
{
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    if (array_key_exists('email_verified', $claims) && $claims['email_verified'] !== true) {
        return null;
    }
    $dominio = substr($email, strrpos($email, '@') + 1);
    return in_array($dominio, ithub_cfg()['dominios'], true) ? $email : null;
}

/** Nombre del perfil de GLPI para el rol de ITHub, o null si el rol no es uno conocido. */
function ithub_perfil_para_rol(string $rol): ?string
{
    $p = ithub_cfg()['perfiles'][$rol] ?? null;
    return is_string($p) && $p !== '' ? $p : null;
}

/**
 * Qué cambiar en las habilitaciones (glpi_profiles_users) de una persona para que su perfil
 * sea el que manda ITHub, sin perder en qué entidades trabaja.
 *
 * - Las filas con un perfil «gestionado» (uno de los del mapeo) distinto del destino pasan al
 *   destino en la misma entidad; si ya había una con el destino en esa entidad, se borran.
 * - Las filas con perfiles que no son del mapeo (Super-Admin, Observer…) y las dinámicas
 *   (reglas, LDAP) no se tocan: son decisiones de un administrador de GLPI.
 * - Si al final no queda ninguna con el destino, se agrega una en la entidad por defecto.
 *
 * @param list<array{id:int,profiles_id:int,entities_id:int,is_recursive:int,is_dynamic:int}> $filas
 * @param list<int> $gestionados ids de los perfiles del mapeo
 * @return array{cambiar:list<int>,borrar:list<int>,agregar:?array{entities_id:int,is_recursive:int}}
 */
function ithub_plan_perfiles(array $filas, int $destino, array $gestionados, int $entidad, bool $recursiva): array
{
    $plan = ['cambiar' => [], 'borrar' => [], 'agregar' => null];
    $conDestino = [];
    foreach ($filas as $f) {
        if ((int) $f['profiles_id'] === $destino) {
            $conDestino[(int) $f['entities_id']] = true;
        }
    }
    foreach ($filas as $f) {
        $perfil = (int) $f['profiles_id'];
        if ((int) $f['is_dynamic'] === 1 || $perfil === $destino || !in_array($perfil, $gestionados, true)) {
            continue;
        }
        $ent = (int) $f['entities_id'];
        if (isset($conDestino[$ent])) {
            $plan['borrar'][] = (int) $f['id'];
        } else {
            $plan['cambiar'][] = (int) $f['id'];
            $conDestino[$ent] = true;
        }
    }
    if ($conDestino === []) {
        $plan['agregar'] = ['entities_id' => $entidad, 'is_recursive' => $recursiva ? 1 : 0];
    }
    return $plan;
}

/**
 * Dirección de salida de ITHub: cierra la sesión única y vuelve al login de GLPI. Con el
 * id_token como pista, ITHub no pregunta.
 */
function ithub_url_salida(?string $idToken, string $volverA): string
{
    $q = ['post_logout_redirect_uri' => $volverA, 'client_id' => ithub_cfg()['client_id']];
    if ($idToken) {
        $q['id_token_hint'] = $idToken;
    }
    return ithub_cfg()['issuer'] . '/oauth/logout?' . http_build_query($q);
}

/** Destino interno después del ingreso: sólo rutas de GLPI ("/..."), nunca otro sitio. */
function ithub_destino_seguro(string $redirect): string
{
    $redirect = trim($redirect);
    if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//') || str_contains($redirect, '\\')
        || preg_match('/[\x00-\x1f]/', $redirect)) {
        return '';
    }
    return $redirect;
}
