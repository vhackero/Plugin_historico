<?php
/**
 * PROCESADOR DE ELIMINACIÓN: SIMULACIÓN O PROGRAMACIÓN
 */
define('NO_DEBUG_DISPLAY', true);
@ini_set('display_errors', '0');
error_reporting(0);

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Limpieza de buffer
while (ob_get_level()) { ob_end_clean(); }
ob_implicit_flush(true);

require_login();
require_capability('moodle/site:config', context_system::instance());

$periodo   = required_param('periodo', PARAM_TEXT);
$dryrun    = optional_param('dryrun', 1, PARAM_INT);
$cron_date = optional_param('cron_date', '', PARAM_TEXT);
$cron_hour = optional_param('cron_hour', 0, PARAM_INT);

function log_console($msg, $color = '#00ff00') {
    echo "<div style='color:$color; font-family:monospace; margin-bottom:2px;'>[".date('H:i:s')."] $msg</div>";
    flush();
}

// CASO A: PROGRAMACIÓN DE BORRADO REAL
if ($dryrun == 0 && !empty($cron_date)) {
    log_console("RECIBIENDO SOLICITUD DE BORRADO REAL...", "#ffffff");

    set_config('cron_delete_periodo', $periodo, 'local_versionamiento_de_aulas');
    set_config('cron_delete_date', $cron_date, 'local_versionamiento_de_aulas');
    set_config('cron_delete_hour', $cron_hour, 'local_versionamiento_de_aulas');

    log_console("REGISTRADO: El borrado real se ejecutará el $cron_date a las $cron_hour:00 hrs.", "#2ecc71");
    log_console("El script CLI será invocado por el Cron automáticamente.", "yellow");
    exit;
}

// CASO B: SIMULACIÓN (Se mantiene tu lógica original)
log_console("INICIANDO MODO SIMULACIÓN PARA EL PERIODO: $periodo", "#3498db");

$sql = "SELECT id, fullname, shortname FROM {course} WHERE id > 1 AND (fullname LIKE ? OR shortname LIKE ?)";
$courses = $DB->get_records_sql($sql, ["%$periodo%", "%$periodo%"]);

if (!$courses) {
    log_console("No se encontraron cursos para el periodo $periodo.", "orange");
    exit;
}

foreach ($courses as $course) {
    log_console("Simulando borrado: [ID: $course->id] $course->fullname", "#00ff00");
}
log_console("Total de cursos a procesar: " . count($courses), "white");