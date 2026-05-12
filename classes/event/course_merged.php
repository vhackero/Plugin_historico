<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class course_merged extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'course';
    }

    public static function get_name() {
        return 'Reutilización del aula';
    }

    public function get_description() {
        return "Reutilización del aula: el usuario '{$this->userid}' fusionó o restauró contenido en el curso '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
