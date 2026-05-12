<?php
/**
 * Interfaz del Docente en Línea: Gestión de Versiones y Reutilización.
 * Control dinámico de visibilidad según periodos de fechas.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/course_merged.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_deleted.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_requested.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_file_retrieved.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_decompressed.php');

$courseid = required_param('id', PARAM_INT);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

require_login($course);

$context = context_course::instance($courseid);

if (!local_versionamiento_de_aulas_user_has_allowed_role((int)$USER->id, (int)$context->id)) {
    throw new moodle_exception('nopermissions', 'error', '', 'Acceso exclusivo para roles configurados en el plugin.');
}

$PAGE->set_url(new moodle_url('/local/versionamiento_de_aulas/index.php', array('id' => $courseid)));
$PAGE->set_context($context);
$PAGE->set_title("Gestión de Versiones");
$PAGE->set_heading($course->fullname);

// --- 1. CONFIGURACIONES DE FECHAS Y RETENCIÓN ---
$hoy = date('Y-m-d');
$respaldo_inicio = get_config('local_versionamiento_de_aulas', 'respaldo_inicio');
$respaldo_fin    = get_config('local_versionamiento_de_aulas', 'respaldo_fin');
$restaurar_inicio = get_config('local_versionamiento_de_aulas', 'restaurar_inicio');
$restaurar_fin    = get_config('local_versionamiento_de_aulas', 'restaurar_fin');

$retentionreference = local_versionamiento_de_aulas_get_retention_reference_timestamp();
$retentionexpiration = local_versionamiento_de_aulas_calculate_retention_expiration($retentionreference);

$puede_respaldar = (!empty($respaldo_inicio) && !empty($respaldo_fin) && $hoy >= $respaldo_inicio && $hoy <= $respaldo_fin);
$puede_restaurar = (!empty($restaurar_inicio) && !empty($restaurar_fin) && $hoy >= $restaurar_inicio && $hoy <= $restaurar_fin);

function formato_fecha_humana($fecha) {
    if (empty($fecha) || $fecha == '---') return '---';
    $meses = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];
    $timestamp = strtotime($fecha);
    return date('j', $timestamp) . " de " . $meses[(int)date('m', $timestamp)] . " del " . date('Y', $timestamp);
}

// --- 2. LÓGICA DE ACCIONES (SOLICITAR / ELIMINAR) ---
// CLAVE: Buscamos el registro que pertenezca a ESTE usuario y a ESTE curso específicamente.
$respaldo_actual = $DB->get_record('local_ver_aulas_cola', [
    'userid'   => $USER->id,
    'courseid' => $courseid
]);

if (optional_param('solicitar', 0, PARAM_INT) && $puede_respaldar && !$respaldo_actual) {
    $requestid = $DB->insert_record('local_ver_aulas_cola', [
        'userid'      => $USER->id,
        'courseid'    => $courseid,
        'status'      => 'pendiente',
        'timecreated' => time()
    ]);

    \local_versionamiento_de_aulas\event\backup_requested::create([
        'objectid' => $requestid,
        'context' => $context,
        'courseid' => $courseid,
        'userid' => $USER->id,
    ])->trigger();

    redirect($PAGE->url, "Solicitud registrada.", 1);
}

if (optional_param('eliminar', 0, PARAM_INT) && $respaldo_actual) {
    $fs = get_file_storage();

    // 1. Borrado por ID de archivo específico
    if (!empty($respaldo_actual->backupfileid)) {
        if ($file = $fs->get_file_by_id($respaldo_actual->backupfileid)) {
            $filename = $file->get_filename();
            local_versionamiento_de_aulas_delete_from_repositories($filename);
            $file->delete();
        }
    }

    // 2. Borrado preventivo del área (usando el ID del registro como itemid)
    $fs->delete_area_files($context->id, 'local_versionamiento_de_aulas', 'backup', $respaldo_actual->id);

    // 3. Borrado de la base de datos (Estricto: id + userid)
    $deletedid = $respaldo_actual->id;
    $DB->delete_records('local_ver_aulas_cola', [
        'id'     => $deletedid,
        'userid' => $USER->id
    ]);

    \local_versionamiento_de_aulas\event\backup_deleted::create([
        'objectid' => $deletedid,
        'context' => $context,
        'courseid' => $courseid,
        'userid' => $USER->id,
    ])->trigger();

    redirect($PAGE->url, "Registro eliminado.", 1);
}

// --- 3. LÓGICA DE FUSIÓN (RESTORE) ---
$file_id = optional_param('file_id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$alreadymerged = $DB->record_exists('local_ver_aulas_logs', [
    'userid' => $USER->id,
    'courseid' => $courseid,
    'action' => 'course_merged',
]);
if ($file_id && $confirm && $puede_restaurar) {
    if ($alreadymerged) {
        redirect($PAGE->url, 'Ya realizaste una reutilización en esta aula.', 2, \core\output\notification::NOTIFY_WARNING);
    }

    echo $OUTPUT->header();
    $admin_user = get_admin();
    $original_user = $USER;
    try {
        @set_time_limit(0);
        \core\session\manager::set_user($admin_user);
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($file_id);
        if (!$file) throw new moodle_exception('filenotfound', 'error');
        $folder = \restore_controller::get_tempdir_name($courseid, $admin_user->id);
        $temp_path = $CFG->dataroot . '/temp/backup/' . $folder;
        check_dir_exists($temp_path, true, true);
        $archive_path = $temp_path . '/' . $file->get_filename();
        $file->copy_content_to($archive_path);

        $formatvalidation = local_versionamiento_de_aulas_validate_restore_course_format($courseid);
        if (!$formatvalidation['matches']) {
            echo $OUTPUT->notification(
                get_string('restoreformatwarning', 'local_versionamiento_de_aulas', (object)[
                    'expected' => $formatvalidation['expected'],
                    'current' => $formatvalidation['current'],
                ]),
                'notifywarning'
            );
        }

        \local_versionamiento_de_aulas\event\backup_file_retrieved::create([
            'objectid' => $file->get_id(),
            'context' => $context,
            'courseid' => $courseid,
            'userid' => $original_user->id,
        ])->trigger();

        $waszst = local_versionamiento_de_aulas_is_zst_filename($archive_path);
        $mbz_path = local_versionamiento_de_aulas_prepare_backup_archive($archive_path);
        if ($waszst) {
            \local_versionamiento_de_aulas\event\backup_decompressed::create([
                'objectid' => $file->get_id(),
                'context' => $context,
                'courseid' => $courseid,
                'userid' => $original_user->id,
            ])->trigger();
        }
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($mbz_path, $temp_path);
        $selectivemergestate = local_versionamiento_de_aulas_prepare_selective_merge((int)$courseid);
        $rc = new \restore_controller($folder, $courseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $admin_user->id, \backup::TARGET_EXISTING_ADDING);
        $restoreplan = $rc->get_plan();
        if ($restoreplan->setting_exists('groups')) {
            $restoreplan->get_setting('groups')->set_value(0);
        }
        if ($rc->execute_precheck()) {
            $rc->execute_plan();
            local_versionamiento_de_aulas_finalize_selective_merge((int)$courseid, $selectivemergestate);
        }
        $rc->destroy();

        $eventclass = '\local_versionamiento_de_aulas\event\course_merged';
        if (class_exists($eventclass)) {
            $eventclass::create([
                'objectid' => $courseid,
                'context' => $context,
                'courseid' => $courseid,
                'userid' => $original_user->id,
            ])->trigger();
        }

        // Registro local para métricas del dashboard admin (compatibilidad con histórico del plugin).
        $DB->insert_record('local_ver_aulas_logs', [
            'userid' => $original_user->id,
            'courseid' => $courseid,
            'action' => 'course_merged',
            'info' => 'Reutilización/fusión de aula completada desde la interfaz web.',
            'timecreated' => time(),
        ]);

        \core\session\manager::set_user($original_user);
        echo $OUTPUT->notification('Contenido fusionado con éxito.', 'notifysuccess');
        echo "<div class='text-center mt-3'><a href='{$CFG->wwwroot}/course/view.php?id={$courseid}' class='btn btn-success rounded-pill'>Volver al Curso</a></div>";
    } catch (Exception $e) {
        \core\session\manager::set_user($original_user);
        echo $OUTPUT->notification("Error: " . $e->getMessage(), 'notifyproblem');
    }
    echo $OUTPUT->footer();
    exit;
}

// --- 4. RENDERIZADO DE INTERFAZ ---
echo $OUTPUT->header();
echo "
<style>
    :root { --inst-guinda: #85192a; }
    .section-title { border-left: 5px solid var(--inst-guinda); padding-left: 15px; color: var(--inst-guinda); font-weight: bold; margin-top: 30px; margin-bottom: 20px; }
    .locked-card { background: #fff9f9; border: 1px solid #f5c6cb; color: #721c24; border-radius: 12px; }
    .active-card { background: #fff; border: 1px solid #eee; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border-radius: 12px; }
    .btn-inst { background: var(--inst-guinda); color: white !important; border-radius: 20px; padding: 8px 25px; font-weight: 600; text-decoration: none !important; }
    .btn-eliminar { background: #d9534f; color: white !important; border-radius: 20px; padding: 10px 30px; font-weight: 600; text-decoration: none !important; }
    .category-path { font-size: 0.8rem; color: #6c757d; text-transform: uppercase; font-weight: 500; display: block; margin-bottom: 2px; }
</style>";

echo "<div class='container-fluid'>";

if (!$puede_respaldar && !$puede_restaurar) {
    echo "<div class='alert locked-card p-5 text-center shadow-sm'>
            <i class='fa fa-calendar-times-o fa-3x mb-3'></i>
            <h4>El periodo para Resguardo y reutilización de aulas no está activo actualmente.</h4>
            <hr>
            <p class='mb-1'><b>Periodo de resguardos:</b> del ".formato_fecha_humana($respaldo_inicio)." al ".formato_fecha_humana($respaldo_fin)."</p>
            <p><b>Periodo de reutilización:</b> del ".formato_fecha_humana($restaurar_inicio)." al ".formato_fecha_humana($restaurar_fin)."</p>
          </div>";
}
else {
    if ($puede_respaldar) {
        echo "<h4 class='section-title'> Respaldar el diseño didáctico de mi aula</h4>";
        echo "<div class='alert active-card p-4 text-center shadow-sm'>";

        if (!$respaldo_actual) {
            echo "<p>Puedes solicitar un resguardo del diseño actual de tu Aula. <br>Habilitado hasta el: <b>".formato_fecha_humana($respaldo_fin)."</b>.</p>";
            echo "<a href='?id={$courseid}&solicitar=1' class='btn btn-dark rounded-pill px-5' style='background-color: #357a32!important;'>SOLICITAR RESGUARDO AHORA</a>";
        } else {
            if ($respaldo_actual->status === 'pendiente' || $respaldo_actual->status === 'procesando') {
                echo "<p class='mb-3'><b>Ya cuentas con una solicitud de resguardo para tu Aula.</b></p>";
                echo "<p class='small text-muted'>Si realizaste cambios en tu aula y necesitas respaldar nuevamente, elimina la solicitud actual.</p>";
                echo "<a href='?id={$courseid}&eliminar=1' class='btn btn-eliminar shadow-sm' style='background-color: #611232!important;' onclick='return confirm(\"Esta acción eliminará tu solicitud de resguardo actual. Puedes solicitar nuevamente un resguardo aquí mismo.\")'>ELIMINAR SOLICITUD DE RESGUARDO</a>";
            } else if ($respaldo_actual->status === 'finalizado') {
                echo "<p><b>Ya cuentas con un resguardo de esta Aula.</b></p>";
                echo "<p class='small text-muted'>Si realizaste cambios en tu aula y necesitas respaldar nuevamente, elimina el resguardo actual:</p>";
                echo "<a href='?id={$courseid}&eliminar=1' class='btn btn-eliminar shadow-sm' style='background-color: #611232!important;' onclick='return confirm(\"Esta acción eliminará tu resguardo actual. Puedes solicitar nuevamente un resguardo aquí mismo.\")'>ELIMINAR RESGUARDO</a>";
                echo "<br><br><p class='small text-muted text-left'>Estimado facilitador, para utilizar este resguardo y copiar el diseño didáctico de esta Aula:<br>
                                                    <ol class='small text-muted text-left'>
                                                    <li class='small text-muted'>Sólo podrás hacerlo en un Aula del siguiente periodo académico.</li>        
                                                    <li class='small text-muted'>Espera a que se habilite el periodo de reutilización de aulas.</li>
                                                    <li class='small text-muted'>Accede a esta misma sección pero en tu nueva Aula.</li>    
                                                    </ol> 
                                                    </p>";
            }
        }
        echo "</div>";
    }

    if ($puede_restaurar) {
        echo "<h4 class='section-title'> Reutilizar diseño didáctico de mi aula</h4>";
        $registros = $DB->get_records('local_ver_aulas_cola', ['userid' => $USER->id, 'status' => 'finalizado'], 'timecreated DESC');

        if ($registros) {
            foreach ($registros as $reg) {
                $dias_restantes = ceil(($retentionexpiration - time()) / 86400);

                $course_orig = $DB->get_record('course', ['id' => $reg->courseid], 'fullname, category');
                $full_category_path = "Sin categoría";

                if ($course_orig) {
                    $category = $DB->get_record('course_categories', ['id' => $course_orig->category]);
                    if ($category) {
                        $path_ids = explode('/', trim($category->path, '/'));
                        $names = [];
                        foreach ($path_ids as $id) {
                            $name = $DB->get_field('course_categories', 'name', ['id' => $id]);
                            if ($name && !in_array(strtolower($name), ['superior', 'system'])) $names[] = $name;
                        }
                        $full_category_path = implode(' &nbsp;>&nbsp; ', $names);
                    }
                }

                echo "<div class='active-card p-3 mb-3 d-flex justify-content-between align-items-center'>
                        <div>
                            <span class='category-path'>{$full_category_path}</span>
                            <div style='font-weight: bold; color: var(--inst-guinda); font-size: 1.1rem;'>{$course_orig->fullname}</div>
                            <div class='text-muted small'>Generado: ".userdate($reg->timecreated, '%d/%m/%Y %H:%M')."</div>";

                if ($dias_restantes > 0) {
                    echo "<div class='small text-success font-weight-bold'><i class='fa fa-clock-o'></i> Disponible por: {$dias_restantes} días más.</div>";
                } else {
                    echo "<div class='small text-danger font-weight-bold'><i class='fa fa-warning'></i> Resguardo expirado.</div>";
                }
                echo "</div>";

                if ($dias_restantes > 0) {
                    $url = new moodle_url($PAGE->url, ['file_id' => $reg->backupfileid, 'confirm' => 1]);
                    echo "<div><a href='{$url}' class='btn-inst shadow-sm' style='background-color: #611232!important;' onclick='return confirm(\"¿Desea reutilizar el contenido de este resguardo en su Aula actual?\")'>Reutilizar</a></div>";
                }
                echo "</div>";
            }
        } else {
            echo "<div class='alert alert-light border text-center'>No tienes resguardos finalizados disponibles para reutilizar.</div>";
        }
    }
}

echo "</div>";
echo $OUTPUT->footer();
