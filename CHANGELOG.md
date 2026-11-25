# Cambios

## 1.0.0 — 25/11/2026

- Plugin `ithub` para GLPI 12.0.x: ingreso con la sesión única de ITHub (OpenID Connect,
  código + PKCE S256, id_token RS256 validado entero: firma con /oauth/jwks, iss, aud, exp y
  nonce).
- Botón «Ingresar con ITHub» en el login de GLPI; el formulario de usuario y contraseña de
  los clientes no cambia.
- Cuenta por email (se crea si no existe) y perfil según el rol de ITHub: `admin` → Admin,
  `mesa` → Crear y Asignar Tickets, `tecnico` → Technician, `autoservicio` → Self-Service.
- Sesión abierta por la autenticación externa de GLPI sólo en memoria: `ssovariables_id`
  queda en 0 en la base.
- Aviso de cierre de ITHub (back-channel logout): revoca las sesiones de GLPI con ese sid.
  «Salir» en GLPI cierra la sesión única.
- `bin/alta-ithub.php` (alta en ITHub, secreto y permisos del personal) y `bin/deploy.sh`.
- 59 pruebas del cliente OIDC y una prueba en el servidor con una cuenta temporal.
- En producción desde el 25/11/2026.
