<?php
/**
 * ELIMINACIÓN FORZADA DE CURSOS - VERSIÓN ULTRA LIMPIEZA
 * Incluye: Moodle Core, Logs Estándar, Cola Local y Registros de Historial.
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/cronlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '-1');
core_php_time_limit::raise(0);

// 1. Definición de parámetros
list($options, $unrecognized) = cli_get_params(
    [
        'coursename' => '',
        'dryrun'     => 'true',
        'force'      => false,
        'deletefile' => false
    ],
    ['d' => 'dryrun', 'f' => 'force']
);

$is_dry_run = true;
if ($options['dryrun'] === false || $options['dryrun'] === 'false' || $options['dryrun'] === 0 || $options['dryrun'] === '0') {
    $is_dry_run = false;
}

if ($is_dry_run) {
    echo "\n╔══════════════════════════════════════════════════════════════╗\n";
    echo "║                     MODO DRY RUN ACTIVADO                    ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
}

// 2. Búsqueda de cursos
$courses_to_delete = [];
if (!empty($options['coursename'])) {
    $search = $options['coursename'];
    $select = $DB->sql_like('fullname', ':p1', false) . " OR " . $DB->sql_like('shortname', ':p2', false);
    $params = ['p1' => "%$search%", 'p2' => "%$search%"];
    $courses_to_delete = $DB->get_records_select('course', $select, $params, 'id ASC');
}

if (empty($courses_to_delete)) {
    echo "✅ No hay cursos para el criterio: " . $options['coursename'] . "\n";
    exit(0);
}

echo "🔍 Encontrados " . count($courses_to_delete) . " cursos para purgar.\n\n";

$target_dir = get_config('local_versionamiento_de_aulas', 'local_repository_path');
if (empty($target_dir)) {
    $target_dir = get_config('local_versionamiento_de_aulas', 'repository_path');
}

foreach ($courses_to_delete as $course) {
    if ($course->id == SITEID) continue;

    echo "📦 Procesando limpieza total: [$course->id] $course->fullname\n";

    if (!$is_dry_run) {
        // A. Obtener contexto del curso y depurar primero la cola del plugin
        // antes del borrado del curso, para poder actualizar estados con trazabilidad.
        $coursecontext = \context_course::instance($course->id, IGNORE_MISSING);
        $registros_cola = $DB->get_records('local_ver_aulas_cola', ['courseid' => $course->id]);
        $fs = get_file_storage();

        foreach ($registros_cola as $registro_cola) {
            $docente = $DB->get_record('user', ['id' => $registro_cola->userid], 'id, suspended, deleted', IGNORE_MISSING);
            $docentebaja = !$docente || ((int)$docente->suspended === 1 || (int)$docente->deleted === 1);

            if ($docentebaja) {
                if (!empty($registro_cola->backupfileid)) {
                    $file = $fs->get_file_by_id($registro_cola->backupfileid);
                    if ($file) {
                        $filename = $file->get_filename();
                        local_versionamiento_de_aulas_delete_from_repositories($filename);
                        $file->delete();
                        echo "   🗑️ Respaldo eliminado por baja docente: {$filename}\n";
                    }
                }

                if ($coursecontext) {
                    $fs->delete_area_files($coursecontext->id, 'local_versionamiento_de_aulas', 'backup', $registro_cola->id);
                }

                $DB->update_record('local_ver_aulas_cola', (object)[
                    'id' => $registro_cola->id,
                    'status' => 'eliminado_baja_docente',
                    'backupfileid' => null,
                    'timemodified' => time(),
                ]);

                $DB->insert_record('local_ver_aulas_logs', [
                    'userid' => (int)$registro_cola->userid,
                    'courseid' => (int)$course->id,
                    'action' => 'respaldo_eliminado_baja_docente',
                    'info' => 'Respaldo eliminado automáticamente por baja/suspensión del docente durante la eliminación del bloque histórico.',
                    'timecreated' => time(),
                ]);
                continue;
            }

            // Comportamiento existente para respaldos de docentes activos.
            if ($options['deletefile'] && !empty($target_dir)) {
                $pattern = $target_dir . "Respaldo_*_ID{$course->id}_*";
                foreach (glob($pattern) as $filename) {
                    if (is_file($filename) && @unlink($filename)) {
                        echo "   🗑️ Archivo externo borrado: " . basename($filename) . "\n";
                    }
                }
            }

            $DB->delete_records('local_ver_aulas_cola', ['id' => $registro_cola->id]);
            echo "   🧹 Registro de cola eliminado.\n";
        }

        // B. BORRADO NATIVO DE MOODLE (Actividades, archivos de curso, notas)
        if (delete_course($course, false)) {
            echo "   ✅ Eliminado de Moodle Core.\n";

            // C. LIMPIEZA DE LOGS ESTÁNDAR (mdl_logstore_standard_log)
            // Borramos por contextid para asegurar que no quede rastro de actividad
            if ($coursecontext) {
                $DB->delete_records('logstore_standard_log', ['contextid' => $coursecontext->id]);
                echo "   🔥 Logs de actividad (standard_log) purgados.\n";
            }

            // E. REGISTRO FINAL DE AUDITORÍA (local_ver_aulas_logs)
            $log = new stdClass();
            $log->courseid    = $course->id;
            $log->userid      = 0;
            $log->action      = 'purga_total_cli';
            $log->info        = "Limpieza absoluta (Curso + Logs + Cola). Criterio: " . $options['coursename'];
            $log->timecreated = time();
            $DB->insert_record('local_ver_aulas_logs', $log);
            echo "   📝 Acción registrada en historial local.\n";

        } else {
            echo "   ❌ Error crítico al borrar curso ID: $course->id\n";
        }
    } else {
        echo "   (Simulación) Se purgarían logs, archivos, registros de cola y el curso.\n";
    }
}

echo "\n✨ Purga total finalizada.\n";
