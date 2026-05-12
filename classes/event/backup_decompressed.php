<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_decompressed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return 'Descompresión del archivo de respaldo';
    }

    public function get_description() {
        return "Descompresión: el archivo de respaldo comprimido fue descomprimido para continuar la restauración en el curso '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/index.php', ['id' => $this->courseid]);
    }
}
