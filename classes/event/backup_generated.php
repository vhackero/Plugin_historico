<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_generated extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Respaldo generado';
    }

    public function get_description() {
        return "Respaldo: se completó la generación del archivo base para el curso '{$this->courseid}' asociado al docente '{$this->userid}' (solicitud '{$this->objectid}').";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/admin_tasks.php');
    }
}
