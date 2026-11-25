<?php

declare(strict_types=1);

/**
 * Pruebas del cliente OpenID Connect (plugin/ithub/inc/oidc.php), sin GLPI ni red.
 *
 * Se firma con una clave RSA generada en el momento y las claves se le pasan a
 * ithub_validar_jwt directamente: no se llama a ITHub.
 *
 *   php test/run.php
 *
 * En Windows openssl_pkey_new necesita el openssl.cnf que trae PHP (se busca solo).
 */

require __DIR__ . '/../plugin/ithub/inc/oidc.php';

$total = 0;
$fallas = 0;
function ok(string $nombre, bool $cond): void
{
    global $total, $fallas;
    $total++;
    if (!$cond) {
        $fallas++;
        echo "  FALLA  $nombre\n";
    }
}
function eq(string $nombre, $actual, $esperado): void
{
    ok($nombre, $actual === $esperado);
    if ($actual !== $esperado) {
        echo '         esperado: ' . var_export($esperado, true) . "\n         obtenido: " . var_export($actual, true) . "\n";
    }
}
function falla(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

// ---- Configuración ----
$GLOBALS['ITHUB_CFG'] = [];
ok('sin client_id ni secreto el plugin no hace nada', !ithub_habilitado());
$GLOBALS['ITHUB_CFG'] = [
    'client_id'     => 'ayuda',
    'client_secret' => 'ith_prueba',
    'url_base'      => 'https://ayuda.intellihelp.tech/',
];
ok('con client_id y secreto, el ingreso con ITHub está activo', ithub_habilitado());
eq('issuer por defecto', ithub_cfg()['issuer'], 'https://ithub.intellihelp.tech');
eq('la vuelta registrada en ITHub', ithub_redirect_uri(), 'https://ayuda.intellihelp.tech/plugins/ithub/front/callback.php');

// ---- Autorización ----
[$url, $estado] = ithub_url_autorizacion();
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
ok('va a /oauth/authorize de ITHub', str_starts_with($url, 'https://ithub.intellihelp.tech/oauth/authorize?'));
eq('con PKCE S256', $q['code_challenge_method'] ?? '', 'S256');
eq('el challenge es el SHA-256 del verificador', $q['code_challenge'] ?? '', ithub_b64url(hash('sha256', $estado['ver'], true)));
eq('el state es el de la sesión', $q['state'] ?? '', $estado['state']);
eq('el nonce es el de la sesión', $q['nonce'] ?? '', $estado['nonce']);
eq('client_id', $q['client_id'] ?? '', 'ayuda');
eq('redirect_uri', $q['redirect_uri'] ?? '', 'https://ayuda.intellihelp.tech/plugins/ithub/front/callback.php');
eq('scopes', $q['scope'] ?? '', 'openid email profile');
[, $estado2] = ithub_url_autorizacion();
ok('cada ingreso tiene su propio state, nonce y verificador',
   $estado2['state'] !== $estado['state'] && $estado2['nonce'] !== $estado['nonce'] && $estado2['ver'] !== $estado['ver']);
ok('el verificador PKCE tiene entre 43 y 128 caracteres', strlen($estado['ver']) >= 43 && strlen($estado['ver']) <= 128);

// ---- Claves y firma ----
$cnf = [];
$ruta = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
if (is_file($ruta)) {
    $cnf['config'] = $ruta;
}
$clave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cnf);
$det = openssl_pkey_get_details($clave);
$jwk = ['kty' => 'RSA', 'kid' => 'k1', 'alg' => 'RS256', 'use' => 'sig',
        'n' => ithub_b64url($det['rsa']['n']), 'e' => ithub_b64url($det['rsa']['e'])];
$claves = ['k1' => $jwk];
$otra = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cnf);

eq('el PEM armado desde el JWK es la misma clave pública',
   str_replace(["\r", "\n"], '', ithub_jwk_a_pem($jwk)), str_replace(["\r", "\n"], '', $det['key']));

$firmar = static function (array $claims, string $kid = 'k1', string $alg = 'RS256', $con = null) use ($clave): string {
    $h = ithub_b64url(json_encode(['alg' => $alg, 'typ' => 'JWT', 'kid' => $kid]));
    $p = ithub_b64url(json_encode($claims));
    openssl_sign("$h.$p", $firma, $con ?? $clave, OPENSSL_ALGO_SHA256);
    return "$h.$p." . ithub_b64url($firma);
};
$base = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'ayuda', 'sub' => '7', 'sid' => str_repeat('a', 32),
         'email' => 'lzaltron@intellihelp.tech', 'email_verified' => true, 'rol' => 'mesa', 'nonce' => 'n1',
         'iat' => time(), 'exp' => time() + 300];

$c = ithub_validar_jwt($firmar($base), 'n1', false, $claves);
eq('un id_token bien firmado pasa y trae el rol', $c['rol'] ?? '', 'mesa');

$partes = explode('.', $firmar($base));
$partes[1] = ithub_b64url(json_encode(['rol' => 'admin'] + $base));
eq('cambiar el contenido rompe la firma', falla(fn() => ithub_validar_jwt(implode('.', $partes), 'n1', false, $claves)), 'firma');
eq('firmado con otra clave no sirve', falla(fn() => ithub_validar_jwt($firmar($base, 'k1', 'RS256', $otra), 'n1', false, $claves)), 'firma');
eq('otro destinatario (aud) no sirve', falla(fn() => ithub_validar_jwt($firmar(['aud' => 'crm'] + $base), 'n1', false, $claves)), 'iss/aud');
eq('aud como lista con nuestro client_id sirve', ithub_validar_jwt($firmar(['aud' => ['x', 'ayuda']] + $base), 'n1', false, $claves)['sub'], '7');
eq('otro emisor (iss) no sirve', falla(fn() => ithub_validar_jwt($firmar(['iss' => 'https://evil.tld'] + $base), 'n1', false, $claves)), 'iss/aud');
eq('vencido no sirve', falla(fn() => ithub_validar_jwt($firmar(['exp' => time() - 120] + $base), 'n1', false, $claves)), 'vencido');
eq('con otro nonce no sirve (repetición)', falla(fn() => ithub_validar_jwt($firmar($base), 'otro', false, $claves)), 'nonce');
eq('sin nonce en la sesión no sirve', falla(fn() => ithub_validar_jwt($firmar($base), '', false, $claves)), 'nonce');
eq('sólo RS256 (none)', falla(fn() => ithub_validar_jwt($firmar($base, 'k1', 'none'), 'n1', false, $claves)), 'alg');
eq('sólo RS256 (HS256)', falla(fn() => ithub_validar_jwt($firmar($base, 'k1', 'HS256'), 'n1', false, $claves)), 'alg');
eq('un kid desconocido no sirve', falla(fn() => ithub_validar_jwt($firmar($base, 'k9'), 'n1', false, $claves)), 'kid');
eq('basura no es un token', falla(fn() => ithub_validar_jwt('no.es-un.token.jwt', 'n1', false, $claves)), 'formato');
eq('vacío no es un token', falla(fn() => ithub_validar_jwt('', 'n1', false, $claves)), 'formato');

// ---- Aviso de cierre ----
$logout = ['iss' => 'https://ithub.intellihelp.tech', 'aud' => 'ayuda', 'sub' => '7', 'sid' => str_repeat('b', 32),
           'iat' => time(), 'exp' => time() + 120, 'jti' => 'x',
           'events' => [ITHUB_EVENTO_LOGOUT => new stdClass()]];
eq('el aviso de cierre válido trae el sid', ithub_validar_jwt($firmar($logout), null, true, $claves)['sid'] ?? '', str_repeat('b', 32));
eq('un id_token no sirve como aviso de cierre', falla(fn() => ithub_validar_jwt($firmar($base), null, true, $claves)), 'logout_token');
eq('un aviso de cierre con nonce se rechaza', falla(fn() => ithub_validar_jwt($firmar(['nonce' => 'x'] + $logout), null, true, $claves)), 'logout_token');
$sinSid = $logout;
unset($sinSid['sid'], $sinSid['sub']);
eq('un aviso sin sid ni sub se rechaza', falla(fn() => ithub_validar_jwt($firmar($sinSid), null, true, $claves)), 'logout_token');
eq('un aviso para otra app se rechaza', falla(fn() => ithub_validar_jwt($firmar(['aud' => 'crm'] + $logout), null, true, $claves)), 'iss/aud');

// ---- Email y rol ----
eq('email del personal', ithub_email_permitido(['email' => 'LZaltron@Intellihelp.tech', 'email_verified' => true]), 'lzaltron@intellihelp.tech');
eq('email de un cliente: no', ithub_email_permitido(['email' => 'fmonaco@srt.gob.ar', 'email_verified' => true]), null);
eq('subdominio parecido: no', ithub_email_permitido(['email' => 'x@intellihelp.tech.evil.tld', 'email_verified' => true]), null);
eq('email sin verificar: no', ithub_email_permitido(['email' => 'lzaltron@intellihelp.tech', 'email_verified' => false]), null);
eq('sin email: no', ithub_email_permitido([]), null);
eq('admin → Admin', ithub_perfil_para_rol('admin'), 'Admin');
eq('mesa → Crear y Asignar Tickets', ithub_perfil_para_rol('mesa'), 'Crear y Asignar Tickets');
eq('tecnico → Technician', ithub_perfil_para_rol('tecnico'), 'Technician');
eq('autoservicio → Self-Service', ithub_perfil_para_rol('autoservicio'), 'Self-Service');
eq('un rol inventado no da perfil', ithub_perfil_para_rol('superadmin'), null);
eq('ITHub nunca da Super-Admin', in_array('Super-Admin', ithub_cfg()['perfiles'], true), false);

// ---- Plan de perfiles (ids reales: Admin 3, Technician 6, Self-Service 1, Crear y Asignar 9, Super-Admin 4) ----
$gest = [3, 9, 6, 1];
$fila = static fn(int $id, int $perfil, int $ent, int $rec = 0, int $din = 0): array =>
    ['id' => $id, 'profiles_id' => $perfil, 'entities_id' => $ent, 'is_recursive' => $rec, 'is_dynamic' => $din];

eq('con el perfil del rol ya puesto no se toca nada',
   ithub_plan_perfiles([$fila(1, 9, 12), $fila(2, 9, 11), $fila(3, 9, 13)], 9, $gest, 0, true),
   ['cambiar' => [], 'borrar' => [], 'agregar' => null]);
eq('cambio de rol: mismo lugar, otro perfil',
   ithub_plan_perfiles([$fila(1, 9, 12), $fila(2, 9, 11)], 3, $gest, 0, true),
   ['cambiar' => [1, 2], 'borrar' => [], 'agregar' => null]);
eq('si ya tenía el destino en esa entidad, la otra fila se borra',
   ithub_plan_perfiles([$fila(1, 9, 12), $fila(2, 3, 12), $fila(3, 9, 13)], 3, $gest, 0, true),
   ['cambiar' => [3], 'borrar' => [1], 'agregar' => null]);
eq('cuenta nueva: se agrega en la entidad por defecto',
   ithub_plan_perfiles([], 3, $gest, 0, true),
   ['cambiar' => [], 'borrar' => [], 'agregar' => ['entities_id' => 0, 'is_recursive' => 1]]);
eq('Super-Admin y las filas dinámicas no se tocan',
   ithub_plan_perfiles([$fila(1, 4, 0, 1), $fila(2, 1, 0, 0, 1)], 9, $gest, 0, true),
   ['cambiar' => [], 'borrar' => [], 'agregar' => ['entities_id' => 0, 'is_recursive' => 1]]);
eq('dos filas gestionadas en la misma entidad: una pasa al destino y la otra se borra',
   ithub_plan_perfiles([$fila(1, 6, 5), $fila(2, 1, 5)], 3, $gest, 0, true),
   ['cambiar' => [1], 'borrar' => [2], 'agregar' => null]);

// ---- Salida y destino ----
$salida = ithub_url_salida('tok.en.x', 'https://ayuda.intellihelp.tech/');
ok('la salida va a /oauth/logout de ITHub', str_starts_with($salida, 'https://ithub.intellihelp.tech/oauth/logout?'));
ok('con el id_token como pista (no pregunta)', str_contains($salida, 'id_token_hint=tok.en.x'));
ok('y vuelve al login de GLPI', str_contains($salida, 'post_logout_redirect_uri=' . rawurlencode('https://ayuda.intellihelp.tech/')));
eq('destino interno sirve', ithub_destino_seguro('/front/ticket.form.php?id=3'), '/front/ticket.form.php?id=3');
eq('otro sitio no', ithub_destino_seguro('https://evil.tld/'), '');
eq('//otro sitio no', ithub_destino_seguro('//evil.tld/'), '');
eq('barra invertida no', ithub_destino_seguro('/\\evil.tld'), '');
eq('saltos de línea no', ithub_destino_seguro("/front/x.php\r\nSet-Cookie: a=b"), '');

echo $fallas === 0 ? "OK: $total comprobaciones\n" : "$fallas de $total comprobaciones fallaron\n";
exit($fallas === 0 ? 0 : 1);
