<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_file_retrieved extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'files';
    }

    public static function get_name() {
        return 'Obtención del archivo de respaldo';
    }

    public function get_description() {
        return "Obtención: se recuperó el archivo de respaldo desde el almacenamiento para iniciar restauración en el curso '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/index.php', ['id' => $this->courseid]);
    }
}
