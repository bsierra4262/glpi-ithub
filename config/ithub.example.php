<?php

/**
 * Configuración del ingreso con ITHub. Va en GLPI/config/ithub.php (fuera del webroot, 600).
 * NO se versiona: el secreto real está en el repo privado glpi-ithub-claves.
 *
 * Sin client_id y client_secret el plugin no hace nada: GLPI sigue exactamente como antes.
 * Sólo las tres primeras claves son obligatorias; el resto muestra los valores por defecto.
 */
return [
    'issuer'        => 'https://ithub.intellihelp.tech',
    'client_id'     => 'ayuda',
    'client_secret' => 'ith_...',   // ITHub → Aplicaciones → Mesa de Ayuda → Generar secreto

    // Dirección pública de GLPI. Por defecto, la de Configuración → General (url_base).
    // 'url_base' => 'https://ayuda.intellihelp.tech',

    // Dominios del personal. Una cuenta de otro dominio no entra por ITHub aunque tenga token.
    // 'dominios' => ['intellihelp.tech'],

    // Rol de ITHub → perfil de GLPI (por nombre). Tiene que coincidir con los roles de la app
    // en ITHub. Super-Admin queda fuera a propósito: sólo la cuenta local `admin`.
    // 'perfiles' => [
    //     'admin'        => 'Admin',
    //     'mesa'         => 'Crear y Asignar Tickets',
    //     'tecnico'      => 'Technician',
    //     'autoservicio' => 'Self-Service',
    // ],

    // Dónde se habilita el perfil a quien entra por primera vez (entidad raíz, recursiva).
    // 'entidad'   => 0,
    // 'recursiva' => true,
];
