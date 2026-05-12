<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_versionamiento_de_aulas\task\eliminar_cursos_task',
        'blocking' => 0,
        'minute' => '*/5',
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ],
    [
        'classname' => '\local_versionamiento_de_aulas\task\generar_respaldos_task',
        'blocking' => 0,
        // Se ejecuta cada X minutos según el selector de frecuencia en settings.php
        'minute' => '*/' . (get_config('local_versionamiento_de_aulas', 'backup_cron_freq') ?: '5'),
        'hour' => '*',
        'day' => '*',
        'dayofweek' => '*',
        'month' => '*'
    ]
];