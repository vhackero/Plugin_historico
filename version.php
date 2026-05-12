<?php
/**
 * Configuración de Versión del Plugin.
 * Ubicación: /local/versionamiento_de_aulas/version.php
 */

defined('MOODLE_INTERNAL') || die();

// El nombre debe ser local_ + nombre_de_la_carpeta
$plugin->component = 'local_versionamiento_de_aulas';
// Usamos un número largo para superar la versión previa de 11 dígitos
$plugin->version   = 20260212011;
$plugin->requires  = 2022111800; // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = 'v1.13';
