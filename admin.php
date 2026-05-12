<?php
/**
 * Panel Administrativo Profesional: Auditoría y Monitor en Tiempo Real.
 * Unifica las tablas de Cola y Logs para mostrar solicitudes instantáneas.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$filter_user   = optional_param('search_user', '', PARAM_TEXT);
$filter_action = optional_param('filter_action', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/versionamiento_de_aulas/admin.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title("Dashboard de Versionamiento");
$PAGE->set_heading("Panel de Control: Versionamiento de Aulas");

echo $OUTPUT->header();

// --- 1. MÉTRICAS ---
$archivos   = $DB->count_records('local_ver_aulas_cola', ['status' => 'finalizado']);
$pendientes = $DB->count_records('local_ver_aulas_cola', ['status' => 'pendiente']);
$logs_count = (int)$DB->count_records_select('local_ver_aulas_logs', "action IN ('fusion_exitosa', 'course_merged')");
$url_cola   = new moodle_url('/local/versionamiento_de_aulas/admin_tasks.php');

echo "
<style>
    .card-stats { border: none; border-radius: 15px; transition: transform 0.2s; text-decoration: none !important; color: inherit; display: block; }
    .card-stats:hover { transform: translateY(-5px); shadow: 0 4px 15px rgba(0,0,0,0.1) !important; }
    .bg-pendientes { background: #fef1d8; border-left: 5px solid #f0ad4e; }
    .bg-archivos { background: #e7f3ff; border-left: 5px solid #007bff; }
    .bg-eventos { background: #eafaf1; border-left: 5px solid #28a745; }
    .filter-box { background: #f8f9fa; border-radius: 10px; padding: 20px; margin-bottom: 25px; border: 1px solid #eee; }
    .badge-fusion { background-color: #28a745; color: white; }
    .badge-pending { background-color: #ffc107; color: #856404; }
    .badge-finished { background-color: #17a2b8; color: white; }
    .badge-delete { background-color: #dc3545; color: white; }
    .badge-info-sys { background-color: #6c757d; color: white; }
    .course-path { font-size: 0.72rem; color: #6c757d; text-transform: uppercase; display: block; margin-bottom: 2px; }
    .course-name-link { font-weight: bold; color: #85192a !important; font-size: 0.92rem; }
</style>

<div class='container-fluid'>
    <div class='row mb-4'>
        <div class='col-md-4'>
            <div class='card card-stats bg-archivos shadow-sm h-100 py-3 text-center'>
                <div class='text-xs font-weight-bold text-primary text-uppercase mb-1 small'>RESGUARDOS EJECUTADOS</div>
                <div class='h2 mb-0 font-weight-bold'>$archivos</div>
            </div>
        </div>
        <div class='col-md-4'>
            <div class='card card-stats bg-eventos shadow-sm h-100 py-3 text-center'>
                <div class='text-xs font-weight-bold text-success text-uppercase mb-1 small'>AULAS REUTILIZADAS</div>
                <div class='h2 mb-0 font-weight-bold'>$logs_count</div>
            </div>
        </div>
        <div class='col-md-4'>
            <a href='$url_cola' class='card card-stats bg-pendientes shadow-sm h-100 py-3 text-center'>
                <div class='text-xs font-weight-bold text-warning text-uppercase mb-1 small'>SOLICITUDES PENDIENTES</div>
                <div class='h2 mb-0 font-weight-bold'>$pendientes</div>
                <small class='text-muted'>Ir a ejecución <i class='fa fa-arrow-right'></i></small>
            </a>
        </div>
    </div>";

// --- 2. FILTROS ---
echo "
<div class='filter-box shadow-sm'>
    <form method='get' action='{$PAGE->url}' class='form-inline justify-content-center'>
        <input type='text' name='search_user' class='form-control mr-2 shadow-sm' placeholder='Nombre o correo electrónico' value='".s($filter_user)."'>
        <select name='filter_action' class='form-control mr-2 shadow-sm'>
            <option value=''>-- Todos los estados --</option>
            <option value='course_merged' ".($filter_action == 'course_merged' ? 'selected' : '').">Reutilización exitosa</option>
            <option value='fusion_exitosa' ".($filter_action == 'fusion_exitosa' ? 'selected' : '').">Reutilización exitosa (legado)</option>
            <option value='pendiente' ".($filter_action == 'pendiente' ? 'selected' : '').">Resguardo pendiente</option>
            <option value='finalizado' ".($filter_action == 'finalizado' ? 'selected' : '').">Resguardo ejecutado</option>
            <option value='respaldo_eliminado' ".($filter_action == 'respaldo_eliminado' ? 'selected' : '').">Resguardo eliminado</option>
        </select>
        <button type='submit' class='btn btn-primary rounded-pill px-4'>Filtrar</button>&nbsp;
        <a href='{$PAGE->url}' class='btn btn-primary rounded-pill px-4'>Limpiar</a>
    </form>
</div>";

// --- 3. CLASE DE TABLA ---
class versionamiento_admin_table extends table_sql {
    function col_timecreated($values) {
        return "<strong>".userdate($values->timecreated, '%d/%m/%Y')."</strong><br><small class='text-muted'>".userdate($values->timecreated, '%H:%M')." hrs</small>";
    }
    function col_userid($values) {
        return "<div>".fullname($values)."</div><small class='text-muted'>{$values->email}</small>";
    }
    function col_courseid($values) {
        global $DB;
        $category = $DB->get_record('course_categories', ['id' => $values->categoryid]);
        $full_path = "Sin categoría";
        if ($category) {
            $ids = explode('/', trim($category->path, '/'));
            $names = [];
            foreach ($ids as $cid) {
                $cname = $DB->get_field('course_categories', 'name', ['id' => $cid]);
                if ($cname && !in_array(strtolower($cname), ['top', 'superior', 'system'])) $names[] = $cname;
            }
            $full_path = implode(' > ', $names);
        }
        $url = new moodle_url('/course/view.php', ['id' => $values->courseid]);
        return "<span class='course-path'>{$full_path}</span>" . html_writer::link($url, $values->coursefullname, ['class' => 'course-name-link', 'target' => '_blank']);
    }
    function col_action($values) {
        $status = $values->action;
        if ($status == 'fusion_exitosa' || $status == 'course_merged') { $c = 'badge-fusion'; $t = 'Reutilización exitosa'; }
        else if ($status == 'pendiente') { $c = 'badge-pending'; $t = 'Resguardo pendiente'; }
        else if ($status == 'finalizado') { $c = 'badge-finished'; $t = 'Resguardo ejecutado'; }
        else if ($status == 'respaldo_eliminado') { $c = 'badge-delete'; $t = 'Resguardo eliminado'; }
        else { $c = 'badge-info-sys'; $t = str_replace('_', ' ', $status); }
        return "<span class='badge $c p-2 w-100' style='border-radius:8px;'>{$t}</span>";
    }
}

// --- 4. CONFIGURACIÓN SQL (UNION PARA TIEMPO REAL) ---
$table = new versionamiento_admin_table('local_ver_table_v3');
$table->define_columns(['timecreated', 'userid', 'courseid', 'action']);
$table->define_headers(['Fecha / Hora', 'Docente', 'Curso / Aula', 'Estado']);
$table->sortable(true, 'timecreated', SORT_DESC);
$table->set_attribute('class', 'table table-hover bg-white shadow-sm border');

// Construimos el WHERE para los filtros
$where = "1=1";
$params = [];
if (!empty($filter_user)) {
    $where .= " AND (u.firstname LIKE :f1 OR u.lastname LIKE :f2 OR u.email LIKE :f3)";
    $params['f1'] = $params['f2'] = $params['f3'] = "%$filter_user%";
}
if (!empty($filter_action)) {
    $where .= " AND combined.action = :action";
    $params['action'] = $filter_action;
}

// El campo "action" en la tabla de cola es el "status"
$sql_fields = "combined.id, combined.timecreated, combined.userid, combined.courseid, combined.action, 
               u.firstname, u.lastname, u.email,
               c.fullname AS coursefullname, c.category AS categoryid";

$sql_from = "(
    SELECT id, userid, courseid, status as action, timecreated FROM {local_ver_aulas_cola}
    UNION
    SELECT id, userid, courseid, action, timecreated FROM {local_ver_aulas_logs}
) combined
JOIN {user} u ON combined.userid = u.id
LEFT JOIN {course} c ON combined.courseid = c.id";

$table->set_sql($sql_fields, $sql_from, $where, $params);
$table->define_baseurl($PAGE->url);
$table->out(20, true);

echo "</div>";
echo $OUTPUT->footer();
