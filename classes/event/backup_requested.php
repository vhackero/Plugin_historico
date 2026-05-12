<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_requested extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Respaldo solicitado por docente';
    }

    public function get_description() {
        return "Respaldo: el docente con id '{$this->userid}' solicitó la generación de un respaldo para el curso '{$this->courseid}' (solicitud '{$this->objectid}').";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/index.php', ['id' => $this->courseid]);
    }
}
