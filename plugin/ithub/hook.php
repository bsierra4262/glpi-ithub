<?php

/**
 * Instalación del plugin: una tabla con el sid de ITHub de cada sesión de GLPI abierta por
 * ITHub, para poder cerrarla cuando ITHub avisa (back-channel logout). Idempotente.
 */

function plugin_ithub_install()
{
    global $DB;

    $tabla = 'glpi_plugin_ithub_sessions';
    if (!$DB->tableExists($tabla)) {
        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();
        $DB->doQuery("CREATE TABLE `$tabla` (
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `sid` varchar(64) NOT NULL DEFAULT '',
            `sub` varchar(64) NOT NULL DEFAULT '',
            `login_session_uid` varchar(64) NOT NULL,
            `users_id` int $sign NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `sid` (`sid`),
            KEY `sub` (`sub`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation ROW_FORMAT=DYNAMIC");
    }
    return true;
}

function plugin_ithub_uninstall()
{
    global $DB;

    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_ithub_sessions`');
    return true;
}
