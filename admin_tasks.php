<?php
/**
 * Panel de Administración: Gestión de cola con Monitor en vivo.
 * Ubicación: /local/versionamiento_de_aulas/admin_tasks.php
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/event/backup_deleted.php');

// Configuración de la página en el menú de administración
admin_externalpage_setup('local_versionamiento_admin');

$action = optional_param('action', '', PARAM_ALPHA);
$id_reg = optional_param('id_reg', 0, PARAM_INT);

// Parámetros de filtros para la tabla
$filter_user   = optional_param('search_user', '', PARAM_TEXT);
$filter_status = optional_param('filter_status', '', PARAM_TEXT);

// --- 1. PROCESAMIENTO AJAX (Monitor en tiempo real) ---
if (optional_param('ajax', 0, PARAM_INT)) {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    // Forzamos que el servidor no guarde la salida en caché para ver el progreso en vivo
    header('Content-Type: text/html');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');

    // Importamos la tarea que realiza el respaldo
    require_once($CFG->dirroot . '/local/versionamiento_de_aulas/classes/task/generar_respaldos_task.php');

    $ids_to_process = optional_param('ids', '', PARAM_TEXT);
    $filter_ids = !empty($ids_to_process) ? explode(',', $ids_to_process) : null;

    $task = new \local_versionamiento_de_aulas\task\generar_respaldos_task();
    // Ejecutamos pasando true para modo manual (ignorar horario) y los IDs seleccionados
    $task->execute(true, $filter_ids);
    exit;
}

// --- 2. LÓGICA DE BORRADO INDIVIDUAL ---
if ($action === 'delete' && $id_reg) {
    $registro = $DB->get_record('local_ver_aulas_cola', ['id' => $id_reg]);
    if ($registro) {
        if (!empty($registro->backupfileid)) {
            $fs = get_file_storage();
            if ($file = $fs->get_file_by_id($registro->backupfileid)) {
                $filename = $file->get_filename();
                local_versionamiento_de_aulas_delete_from_repositories($filename);
                $file->delete();
            }
        }

        $coursecontext = \context_course::instance($registro->courseid, IGNORE_MISSING);
        if ($coursecontext) {
            $fs = get_file_storage();
            $fs->delete_area_files($coursecontext->id, 'local_versionamiento_de_aulas', 'backup', $registro->id);
        }

        $DB->insert_record('local_ver_aulas_logs', [
            'userid'      => $registro->userid,
            'courseid'    => $registro->courseid,
            'action'      => 'respaldo_eliminado',
            'info'        => 'Eliminado manualmente de la cola por el administrador y removido de repositorios.',
            'timecreated' => time()
        ]);
        $DB->delete_records('local_ver_aulas_cola', ['id' => $id_reg]);

        $eventcontext = \context_course::instance($registro->courseid, IGNORE_MISSING) ?: \context_system::instance();
        \local_versionamiento_de_aulas\event\backup_deleted::create([
            'objectid' => $id_reg,
            'context' => $eventcontext,
            'courseid' => $registro->courseid,
            'userid' => $USER->id,
        ])->trigger();

        redirect(new moodle_url('/local/versionamiento_de_aulas/admin_tasks.php'), "Registro eliminado", 1);
    }
}

// --- 3. CLASE DE LA TABLA ---
class local_ver_tasks_table extends table_sql {
    public function col_checkbox($values) {
        return '<input type="checkbox" class="item-checkbox" value="'.$values->id.'" data-status="'.$values->status.'">';
    }
    public function col_details($values) {
        global $DB;
        $course_name = $DB->get_field('course', 'fullname', ['id' => $values->courseid]);
        return '<div style="color:#85192a; font-weight:bold;">'.s($course_name).'</div>' .
            '<div class="small text-muted"><i class="fa fa-user"></i> '.fullname($values).' ('.$values->email.')</div>';
    }
    public function col_timecreated($values) {
        return userdate($values->timecreated, '%d/%m/%y %H:%M');
    }
    public function col_status($values) {
        $status_map = [
            'pendiente'  => 'badge-warning',
            'procesando' => 'badge-primary',
            'finalizado' => 'badge-success',
            'error'      => 'badge-danger'
        ];
        $class = $status_map[$values->status] ?? 'badge-info';
        return '<span class="badge '.$class.'">'.ucfirst(s($values->status)).'</span>';
    }
    public function col_actions($values) {
        $out = '<div class="text-center">';
        if ($values->status !== 'finalizado') {
            $out .= '<a href="javascript:void(0);" class="btn btn-link btn-exe-single p-1 mr-2" title="Ejecutar ahora" data-id="'.$values->id.'">
                        <i class="fa fa-play-circle fa-lg text-success"></i></a>';
        }
        $delurl = new moodle_url('/local/versionamiento_de_aulas/admin_tasks.php', ['action' => 'delete', 'id_reg' => $values->id]);
        $out .= '<a href="'.$delurl.'" class="btn btn-link text-danger p-1" onclick="return confirm(\'¿Eliminar de la cola?\')">
                    <i class="fa fa-trash-o fa-lg"></i></a>';
        $out .= '</div>';
        return $out;
    }
}

// --- 4. RENDERIZADO DE LA PÁGINA ---
echo $OUTPUT->header();
$hay_pendientes = $DB->record_exists('local_ver_aulas_cola', ['status' => 'pendiente']);
?>

    <style>
        .dash-card { border-radius: 12px; border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.1); background: #fff; }
        .console-box { background: #1a1a1a; color: #33ff33; padding: 15px; border-radius: 8px; height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.85rem; border: 1px solid #333; }
        .row-selected { background-color: #fff9e6 !important; }
    </style>

    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="mb-0 text-muted"><i class="fa fa-tasks mr-2"></i> Gestión de reutilización de aulas</h3>
            <div>
                <button id="btn-execute-selected" class="btn btn-outline-success shadow-sm px-4 rounded-pill mr-2" disabled>
                    <i class="fa fa-check-square-o"></i> Ejecutar Seleccionados
                </button>
                <button id="btn-start-all" class="btn btn-primary shadow-sm px-4 rounded-pill" <?php echo $hay_pendientes ? '' : 'disabled'; ?>>
                    <i class="fa fa-play-circle"></i> Procesar Todo
                </button>
            </div>
        </div>

        <div class="card dash-card mb-4 bg-light border">
            <div class="card-body">
                <form method="get" action="admin_tasks.php" class="form-inline">
                    <input type="text" name="search_user" class="form-control mr-2" placeholder="Nombre o correo" value="<?php echo s($filter_user); ?>">
                    <select name="filter_status" class="form-control mr-2">
                        <option value="">-- Todos los estados --</option>
                        <option value="pendiente" <?php echo ($filter_status == 'pendiente' ? 'selected' : ''); ?>>Pendiente</option>
                        <option value="finalizado" <?php echo ($filter_status == 'finalizado' ? 'selected' : ''); ?>>Finalizado</option>
                        <option value="error" <?php echo ($filter_status == 'error' ? 'selected' : ''); ?>>Error</option>
                    </select>
                    <button type="submit" class="btn btn-primary rounded-pill px-4">Filtrar</button>&nbsp;
                    <a href="admin_tasks.php" class="btn btn-secondary rounded-pill px-4">Limpiar</a>
                </form>
            </div>
        </div>

        <div id="exec-zone" style="display:none;" class="card dash-card mb-4 border-left border-primary">
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <h6 class="text-primary mb-0"><i class="fa fa-terminal"></i> Monitor de ejecución</h6>
                    <span id="p-text" class="badge badge-primary">0%</span>
                </div>
                <div class="progress mb-3" style="height:10px;">
                    <div id="p-bar" class="progress-bar progress-bar-striped animated" style="width:0%"></div>
                </div>
                <div id="p-console" class="console-box"></div>
            </div>
        </div>

        <div class="card dash-card shadow-sm">
            <div class="card-body p-0">
                <?php
                $table = new local_ver_tasks_table('local_ver_tasks_v4');
                $table->define_columns(['checkbox', 'details', 'timecreated', 'status', 'actions']);
                $table->define_headers(['', 'Aula / Docente', 'Fecha Solicitud', 'Estado', 'Acciones']);
                $table->sortable(true, 'timecreated', SORT_DESC);
                $table->no_sorting('checkbox', 'actions');
                $table->set_attribute('class', 'table table-hover mb-0');

                $sql_where = "1=1";
                $params = [];
                if (!empty($filter_user)) {
                    $sql_where .= " AND (u.firstname LIKE :u1 OR u.lastname LIKE :u2 OR u.email LIKE :u3)";
                    $params['u1'] = $params['u2'] = $params['u3'] = "%$filter_user%";
                }
                if (!empty($filter_status)) {
                    $sql_where .= " AND q.status = :st";
                    $params['st'] = $filter_status;
                }

                $table->set_sql("q.id, q.userid, q.courseid, q.status, q.timecreated, u.firstname, u.lastname, u.email",
                    "{local_ver_aulas_cola} q JOIN {user} u ON q.userid = u.id", $sql_where, $params);

                $table->define_baseurl($PAGE->url);
                $table->out(15, true);
                ?>
            </div>
        </div>
    </div>

    <script>
        /**
         * Actualiza la UI desde el servidor.
         * Esta función debe ser GLOBAL para que los scripts inyectados la encuentren.
         */
        function updateUI(msg, percent) {
            const bar = document.getElementById('p-bar');
            const txt = document.getElementById('p-text');
            const log = document.getElementById('p-console');

            if (bar) bar.style.width = percent + '%';
            if (txt) txt.innerText = percent + '%';
            if (log && msg) {
                log.innerHTML += `<div><span style="color:#888">[${new Date().toLocaleTimeString()}]</span> ${msg}</div>`;
                log.scrollTop = log.scrollHeight;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const btnAll = document.getElementById('btn-start-all');
            const btnSelected = document.getElementById('btn-execute-selected');

            function runProcess(ids = '') {
                // Mostrar consola y bloquear botones
                document.getElementById('exec-zone').style.display = 'block';
                if(btnAll) btnAll.disabled = true;
                if(btnSelected) btnSelected.disabled = true;

                document.getElementById('p-console').innerHTML = '<div>[SISTEMA] Iniciando conexión con el servidor...</div>';

                // URL Limpia para AJAX
                let ajaxUrl = window.location.origin + window.location.pathname + '?ajax=1';
                if (ids) ajaxUrl += '&ids=' + ids;

                const xhr = new XMLHttpRequest();
                xhr.open('GET', ajaxUrl, true);
                let lastLen = 0;

                xhr.onreadystatechange = function() {
                    // Estado 3 es "Loading" (recibiendo chunks)
                    if (xhr.readyState === 3 || xhr.readyState === 4) {
                        let chunk = xhr.responseText.substring(lastLen);
                        lastLen = xhr.responseText.length;

                        // Crear un contenedor temporal para extraer y ejecutar los scripts
                        let div = document.createElement('div');
                        div.innerHTML = chunk;
                        let scripts = div.getElementsByTagName('script');
                        for (let s of scripts) {
                            try {
                                eval(s.innerHTML); // Ejecuta el updateUI() enviado desde PHP
                            } catch(e) { console.warn("Esperando cierre de script..."); }
                        }
                    }
                    // Estado 4 es "Done"
                    if (xhr.readyState === 4) {
                        updateUI("Proceso finalizado. Recargando...", 100);
                        setTimeout(() => { window.location.reload(); }, 2500);
                    }
                };
                xhr.send();
            }

            // Listener para botones principales
            if(btnAll) {
                btnAll.addEventListener('click', function(e) {
                    e.preventDefault();
                    runProcess();
                });
            }

            if(btnSelected) {
                btnSelected.addEventListener('click', function(e) {
                    e.preventDefault();
                    const ids = Array.from(document.querySelectorAll('.item-checkbox:checked')).map(cb => cb.value);
                    if(ids.length) runProcess(ids.join(','));
                });
            }

            // Listener para clics en la tabla (play individual)
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.btn-exe-single');
                if (btn) {
                    e.preventDefault();
                    runProcess(btn.dataset.id);
                }
            });

            // Control de selección de checkboxes
            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('item-checkbox')) {
                    const checked = document.querySelectorAll('.item-checkbox:checked');
                    if(btnSelected) {
                        btnSelected.disabled = (checked.length === 0);
                        btnSelected.innerHTML = `<i class="fa fa-check-square-o"></i> Ejecutar (${checked.length}) seleccionados`;
                    }
                }
            });
        });
    </script>

<?php
echo $OUTPUT->footer();