<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_stored extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Almacenamiento del respaldo';
    }

    public function get_description() {
        $destination = $this->other['destination'] ?? 'desconocido';
        return "Almacenamiento: el respaldo comprimido del curso '{$this->courseid}' se guardó en el destino '{$destination}' para la solicitud '{$this->objectid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/admin_tasks.php');
    }
}
