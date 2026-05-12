<?php
namespace local_versionamiento_de_aulas\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_generated.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_compressed.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_stored.php');

class generar_respaldos_task extends \core\task\scheduled_task {
    public function get_name() { return "Procesar cola de resguardos de aulas"; }

    /**
     * Ejecuta el procesamiento de la cola.
     * @param bool $manual Indica si se ejecuta desde AJAX.
     * @param string|array|null $filter_ids IDs específicos a procesar.
     */
    public function execute($manual = false, $filter_ids = null) {
        global $DB, $CFG;

        $clearcronprogramming = false;
        $scheduledtimestamp = null;
        if (!$manual) {
            $progfecha = trim((string)get_config('local_versionamiento_de_aulas', 'backup_cron_date'));
            $proghoraraw = trim((string)get_config('local_versionamiento_de_aulas', 'backup_cron_hour'));

            // En ejecución automática sólo se procesa si existen fecha y hora válidas.
            if ($progfecha === '' || $proghoraraw === '') {
                return;
            }

            if (!preg_match('/^\d{1,2}$/', $proghoraraw)) {
                return;
            }

            $proghora = (int)$proghoraraw;
            if ($proghora < 0 || $proghora > 23) {
                return;
            }

            $scheduleddate = null;
            foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $dateformat) {
                $parseddate = \DateTimeImmutable::createFromFormat($dateformat, $progfecha);
                if ($parseddate instanceof \DateTimeImmutable) {
                    $scheduleddate = $parseddate;
                    break;
                }
            }

            if (!$scheduleddate) {
                return;
            }

            $scheduled = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                $scheduleddate->format('Y-m-d') . ' ' . sprintf('%02d:00', $proghora)
            );
            if (!$scheduled) {
                // Si el valor configurado no es válido, no procesamos para evitar ejecuciones anticipadas.
                return;
            }

            $scheduledtimestamp = $scheduled->getTimestamp();

            $now = new \DateTimeImmutable('now');
            if ($now < $scheduled) {
                return;
            }

            // Programación de una sola ejecución: al abrir ventana, se limpia al finalizar.
            $clearcronprogramming = true;
        }

        $params = ['status' => 'pendiente'];
        $sql_where = "status = :status";

        if (!$manual && $scheduledtimestamp !== null) {
            // Solo procesa pendientes solicitados hasta la hora programada.
            $sql_where .= " AND timecreated <= :scheduledts";
            $params['scheduledts'] = $scheduledtimestamp;
        }

        if ($manual && !empty($filter_ids)) {
            if (!is_array($filter_ids)) { $filter_ids = explode(',', $filter_ids); }
            list($insql, $inparams) = $DB->get_in_or_equal($filter_ids, SQL_PARAMS_NAMED);
            $sql_where .= " AND id $insql";
            $params = array_merge($params, $inparams);
        }

        $tareas = $DB->get_records_select('local_ver_aulas_cola', $sql_where, $params, 'timecreated ASC');
        $total = count($tareas);
        $count = 0;
        $admin = get_admin();
        $use_repository_path = (bool)get_config('local_versionamiento_de_aulas', 'use_repository_path');

        if (empty($tareas)) {
            if ($clearcronprogramming) {
                set_config('backup_cron_date', '', 'local_versionamiento_de_aulas');
                set_config('backup_cron_hour', '', 'local_versionamiento_de_aulas');
            }
            if ($manual) $this->web_log("No hay resguardos pendientes.", 100);
            return;
        }

        foreach ($tareas as $t) {
            $count++;
            $p = round(($count / $total) * 100);
            $course = $DB->get_record('course', ['id' => $t->courseid], 'shortname');
            $shortname = $course ? $course->shortname : "ID {$t->courseid}";

            if ($manual) $this->web_log("Iniciando: {$shortname}", $p);

            $DB->set_field('local_ver_aulas_cola', 'status', 'procesando', ['id' => $t->id]);

            try {
                $bc = new \backup_controller(\backup::TYPE_1COURSE, $t->courseid, \backup::FORMAT_MOODLE,
                    \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $admin->id);

                $plan = $bc->get_plan();
                if ($plan->setting_exists('users')) {
                    $plan->get_setting('users')->set_value(0);
                }
                if ($plan->setting_exists('groups')) {
                    $plan->get_setting('groups')->set_value(0);
                }
                $bc->execute_plan();

                $results = $bc->get_results();
                if (isset($results['backup_destination'])) {
                    $file = $results['backup_destination'];

                    $workdir = make_request_directory('local_versionamiento_de_aulas');
                    $clean_name = clean_filename($shortname);
                    $new_filename = "Respaldo_{$clean_name}_ID{$t->courseid}_T{$t->id}_" . date('Ymd_His') . ".mbz";
                    $mbzpath = $workdir . '/' . $new_filename;
                    $file->copy_content_to($mbzpath);

                    $zstpath = local_versionamiento_de_aulas_compress_mbz_to_zst($mbzpath);
                    $zstfilename = basename($zstpath);
                    $eventcontext = \context_course::instance($t->courseid, IGNORE_MISSING);
                    if ($eventcontext) {
                        \local_versionamiento_de_aulas\event\backup_compressed::create([
                            'objectid' => $t->id,
                            'context' => $eventcontext,
                            'courseid' => $t->courseid,
                            'userid' => $t->userid,
                        ])->trigger();
                    }

                    $destination = 'local';
                    if ($use_repository_path) {
                        local_versionamiento_de_aulas_copy_to_repository($zstpath);
                        $destination = 'remoto';
                    } else {
                        local_versionamiento_de_aulas_copy_to_local_repository($zstpath);
                    }

                    $fs = get_file_storage();
                    $file_record = [
                        'contextid' => \context_course::instance($t->courseid)->id,
                        'component' => 'local_versionamiento_de_aulas',
                        'filearea' => 'backup',
                        'itemid' => $t->id,
                        'filepath' => '/',
                        'filename' => $zstfilename,
                        'userid' => $t->userid,
                    ];
                    $stored_file = $fs->create_file_from_pathname($file_record, $zstpath);

                    $DB->update_record('local_ver_aulas_cola', (object)[
                        'id' => $t->id,
                        'status' => 'finalizado',
                        'backupfileid' => $stored_file->get_id(),
                        'timemodified' => time()
                    ]);

                    if ($eventcontext) {
                        \local_versionamiento_de_aulas\event\backup_stored::create([
                            'objectid' => $t->id,
                            'context' => $eventcontext,
                            'courseid' => $t->courseid,
                            'userid' => $t->userid,
                            'other' => ['destination' => $destination],
                        ])->trigger();

                        \local_versionamiento_de_aulas\event\backup_generated::create([
                            'objectid' => $t->id,
                            'context' => $eventcontext,
                            'courseid' => $t->courseid,
                            'userid' => $t->userid,
                        ])->trigger();
                    }

                    if ($manual) $this->web_log("Completado: {$shortname}", $p);
                }
                $bc->destroy();
            } catch (\Exception $e) {
                $DB->set_field('local_ver_aulas_cola', 'status', 'error', ['id' => $t->id]);
                $this->log_event($t->userid, $t->courseid, 'respaldo_error', $e->getMessage());
                if ($manual) $this->web_log("ERROR en {$shortname}: " . $e->getMessage(), $p);
            }
        }
        if ($clearcronprogramming) {
            set_config('backup_cron_date', '', 'local_versionamiento_de_aulas');
            set_config('backup_cron_hour', '', 'local_versionamiento_de_aulas');
        }
        if ($manual) $this->web_log("Proceso terminado.", 100);
    }

    private function log_event($userid, $courseid, $action, $info) {
        global $DB;

        try {
            $DB->insert_record('local_ver_aulas_logs', (object)[
                'userid' => (int)$userid,
                'courseid' => (int)$courseid,
                'action' => $action,
                'info' => $info,
                'timecreated' => time(),
            ]);
        } catch (\Exception $ignored) {
            // Evitamos romper el flujo principal por fallos de auditoría.
        }
    }

    private function web_log($m, $p) {
        // Añadimos un relleno de 4096 bytes para forzar el envío en navegadores como Chrome/Edge
        echo "<script>if(typeof updateUI === 'function'){ updateUI('".addslashes($m)."', {$p}); }</script>\n";
        echo str_pad('', 4096) . "\n";

        // Limpiamos todos los niveles de búfer activos
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}