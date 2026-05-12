<?php
/**
 * Punto de entrada CLI legado del plugin.
 *
 * IMPORTANTE:
 * Este archivo existía con lógica propia que procesaba pendientes inmediatamente,
 * ignorando la fecha/hora configurada en settings. Ahora delega en la tarea
 * programada oficial para respetar la programación.
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/task/generar_respaldos_task.php');

$task = new \local_versionamiento_de_aulas\task\generar_respaldos_task();
$task->execute(false);
