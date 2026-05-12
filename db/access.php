<?php
/**
 * Definición de capacidades del plugin.
 */
defined('MOODLE_INTERNAL') || die();

$capabilities = array(
    'local/versionamiento_de_aulas:gestionar' => array(
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => array(
            'manager' => CAP_ALLOW
        )
    ),
);