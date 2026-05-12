<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_compressed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Compresión del respaldo';
    }

    public function get_description() {
        return "Compresión: el respaldo del curso '{$this->courseid}' fue comprimido en formato Zstandard (.zst) para la solicitud '{$this->objectid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/admin_tasks.php');
    }
}
