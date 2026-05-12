<?php
namespace local_versionamiento_de_aulas\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_file_retrieved.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_decompressed.php');

class restaurar_version_task {

    public function ejecutar_restauracion($courseid, $fileid, $userid, $isweb = false) {
        global $DB, $CFG;

        try {
            $this->log("Iniciando proceso de restauración...", $isweb, 10);

            // 1. Obtener el archivo desde el file storage de Moodle
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($fileid);

            if (!$file) {
                throw new \moodle_exception('filenotfound', 'error');
            }

            // 2. Preparar el directorio temporal de restauración
            $folder = restore_controller::get_tempdir_name($courseid, $userid);
            $path = $CFG->dataroot . '/temp/backup/' . $folder;
            check_dir_exists($path, true, true);

            $archivepath = $path . '/' . $file->get_filename();
            $file->copy_content_to($archivepath);

            $formatvalidation = local_versionamiento_de_aulas_validate_restore_course_format($courseid);
            if (!$formatvalidation['matches']) {
                $this->log(
                    get_string('restoreformatwarning', 'local_versionamiento_de_aulas', (object)[
                        'expected' => $formatvalidation['expected'],
                        'current' => $formatvalidation['current'],
                    ]),
                    $isweb,
                    20
                );
            }

            $eventcontext = \context_course::instance($courseid, IGNORE_MISSING);
            if ($eventcontext) {
                \local_versionamiento_de_aulas\event\backup_file_retrieved::create([
                    'objectid' => $fileid,
                    'context' => $eventcontext,
                    'courseid' => $courseid,
                    'userid' => $userid,
                ])->trigger();
            }

            $waszst = local_versionamiento_de_aulas_is_zst_filename($archivepath);
            $mbzpath = local_versionamiento_de_aulas_prepare_backup_archive($archivepath);
            if ($waszst && $eventcontext) {
                \local_versionamiento_de_aulas\event\backup_decompressed::create([
                    'objectid' => $fileid,
                    'context' => $eventcontext,
                    'courseid' => $courseid,
                    'userid' => $userid,
                ])->trigger();
            }
            get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($mbzpath, $path);
            $selectivemergestate = local_versionamiento_de_aulas_prepare_selective_merge((int)$courseid);

            $this->log("Archivo extraído. Configurando controlador...", $isweb, 40);

            // 3. Instanciar el restore_controller
            // MODE_SAMESITE: Restaura sobre el curso actual (borrando o mezclando)
            // TARGET_EXISTING_ADDING: Mezcla el contenido actual con el del respaldo
            // TARGET_EXISTING_DELETING: Borra el curso actual y pone el del respaldo
            $rc = new \restore_controller($folder, $courseid,
                \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $userid,
                \backup::TARGET_EXISTING_DELETING);
            $restoreplan = $rc->get_plan();
            if ($restoreplan->setting_exists('groups')) {
                $restoreplan->get_setting('groups')->set_value(0);
            }

            // 4. Ejecutar validaciones y plan de restauración
            if ($rc->execute_precheck()) {
                $this->log("Validación exitosa. Sobrescribiendo aula...", $isweb, 70);
                $rc->execute_plan();
                local_versionamiento_de_aulas_finalize_selective_merge((int)$courseid, $selectivemergestate);
                $this->log("✅ Aula restaurada con éxito.", $isweb, 100);
            } else {
                throw new \moodle_exception('error_precheck', 'local_versionamiento_de_aulas');
            }

            $rc->destroy();

        } catch (\Exception $e) {
            $this->log("❌ Error: " . $e->getMessage(), $isweb, 0);
        }
    }

    private function log($m, $web, $p) {
        if ($web) {
            echo "<script>updateUI('".addslashes($m)."', {$p});</script>";
            echo str_pad("", 4096);
            flush();
        }
    }
}
