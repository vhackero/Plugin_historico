<?php
/**
 * Upgrade script for local_versionamiento_de_aulas.
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Executes local plugin upgrades.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_versionamiento_de_aulas_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 20260212007) {
        $table = new xmldb_table('local_ver_aulas_cola');
        $field = new xmldb_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'timecreated');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 20260212007, 'local', 'versionamiento_de_aulas');
    }

    if ($oldversion < 20260212008) {
        $table = new xmldb_table('local_ver_aulas_cola');
        $field = new xmldb_field('status', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, 'pendiente', 'courseid');

        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_type($table, $field);
        }

        upgrade_plugin_savepoint(true, 20260212008, 'local', 'versionamiento_de_aulas');
    }

    if ($oldversion < 20260212009) {
        // Versión de mantenimiento para refrescar metadatos de eventos en despliegue.
        upgrade_plugin_savepoint(true, 20260212009, 'local', 'versionamiento_de_aulas');
    }

    if ($oldversion < 20260212010) {
        // Versión de mantenimiento para incorporar validación de formato en restauración.
        upgrade_plugin_savepoint(true, 20260212010, 'local', 'versionamiento_de_aulas');
    }

    if ($oldversion < 20260212011) {
        // Versión de mantenimiento para alinear validación con formato Buttons (Aulas 2024).
        upgrade_plugin_savepoint(true, 20260212011, 'local', 'versionamiento_de_aulas');
    }

    return true;
}
