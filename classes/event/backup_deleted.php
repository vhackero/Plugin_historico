<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_deleted extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Respaldo eliminado';
    }

    public function get_description() {
        return "Respaldo: el usuario '{$this->userid}' eliminó un respaldo del curso '{$this->courseid}', incluyendo su limpieza en repositorio configurado cuando aplica.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/admin_tasks.php');
    }
}
