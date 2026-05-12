<?php
namespace local_versionamiento_de_aulas\task;

defined('MOODLE_INTERNAL') || die();

class eliminar_cursos_task extends \core\task\scheduled_task {
    public function get_name() {
        return "Eliminación programada de aulas (Histórico)";
    }

    public function execute() {
        global $DB, $CFG;

        $hoy = date('Y-m-d');
        $hora_actual = (int)date('H');

        $del_periodo = get_config('local_versionamiento_de_aulas', 'cron_delete_periodo');
        $del_fecha   = get_config('local_versionamiento_de_aulas', 'cron_delete_date');
        $del_hora    = get_config('local_versionamiento_de_aulas', 'cron_delete_hour');

        if (!empty($del_periodo) && $del_fecha === $hoy && $hora_actual >= (int)$del_hora) {
            mtrace("Iniciando Proceso: Borrado de Cursos del periodo $del_periodo");

            $cli_path = $CFG->dirroot . '/local/versionamiento_de_aulas/cli/eliminar_cursos_forzado.php';

            if (file_exists($cli_path)) {
                // Comando con dryrun=false para ejecución real
                $command = "php " . escapeshellarg($cli_path) . " --coursename=" . escapeshellarg($del_periodo) . " --force --dryrun=false 2>&1";
                $output = shell_exec($command);

                mtrace("--- SALIDA ELIMINACIÓN CLI ---");
                mtrace($output);
                mtrace("------------------------------");

                set_config('cron_delete_periodo', '', 'local_versionamiento_de_aulas');
                set_config('cron_delete_date', '', 'local_versionamiento_de_aulas');
                set_config('cron_delete_hour', '', 'local_versionamiento_de_aulas');
            } else {
                mtrace("Error: No se encontró eliminar_cursos_forzado.php");
            }
        }
    }
}