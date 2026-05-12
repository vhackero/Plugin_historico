<?php
/**
 * INTERFAZ DE ADMINISTRACIÓN CON PROGRAMACIÓN DE BORRADO
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('local_versionamiento_delete');

// --- LÓGICA DE CANCELACIÓN DE TAREA (Añadido) ---
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'cancel' && confirm_sesskey()) {
    set_config('cron_delete_periodo', '', 'local_versionamiento_de_aulas');
    set_config('cron_delete_date', '', 'local_versionamiento_de_aulas');
    set_config('cron_delete_hour', '', 'local_versionamiento_de_aulas');
    redirect(new moodle_url('/local/versionamiento_de_aulas/admin_delete_courses.php'), 'Programación eliminada correctamente', 2);
}

echo $OUTPUT->header();

// --- CONSULTAR TAREA PROGRAMADA ACTUAL ---
$current_period = get_config('local_versionamiento_de_aulas', 'cron_delete_periodo');
$current_date   = get_config('local_versionamiento_de_aulas', 'cron_delete_date');
$current_hour   = get_config('local_versionamiento_de_aulas', 'cron_delete_hour');
?>
    <div class="container-fluid">
        <h3 class="mb-4" style='color: #611232!important;'>Histórico de Aulas</h3>
        <div class="row">
            <div class="col-md-4">
                <div class="card shadow-sm mb-4" style="border-color: #611232 !important;">
                    <div class="card-body">
                        <form id="delete-form">
                            <div class="form-group">
                                <label class="font-weight-bold">Seleccionar periodo:</label>
                                <select name="periodo" class="form-control" id="periodo_select">
                                    <option value="">-- Seleccione --</option>
                                    <?php
                                    $courses = $DB->get_records_sql("SELECT fullname FROM {course} WHERE id > 1");
                                    $periodos_encontrados = [];
                                    foreach ($courses as $c) {
                                        if (preg_match('/(202[0-9]-[1-2])-(B[1-2])/i', $c->fullname, $m)) {
                                            $periodo_completo = strtoupper($m[0]);
                                            $bloque_base = strtoupper($m[1]);
                                            $periodos_encontrados[$periodo_completo] = $bloque_base;
                                        }
                                    }
                                    krsort($periodos_encontrados);
                                    $bloques_unicos = array_values(array_unique($periodos_encontrados));
                                    $bloque_protegido_1 = $bloques_unicos[0] ?? null;
                                    $bloque_protegido_2 = $bloques_unicos[1] ?? null;

                                    foreach ($periodos_encontrados as $pc => $bb) {
                                        $esta_protegido = ($bb === $bloque_protegido_1 || $bb === $bloque_protegido_2);
                                        $disabled = $esta_protegido ? "disabled style='color:#a0a0a0; background:#f8f9fa;'" : "";
                                        echo "<option value='{$pc}' {$disabled}>{$pc}</option>";
                                    }
                                    ?>
                                </select>
                                <small class="text-muted mt-2 d-block">
                                    <i class="fa fa-info-circle"></i> Se han protegido automáticamente los dos periodos más recientes.
                                </small>
                            </div>

                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="dryrun_check" checked onchange="toggleCronFields()">
                                <label class="custom-control-label" for="dryrun_check">Modo simulación</label>
                            </div>

                            <div id="cron_execution_fields" style="display:none;" class="alert alert-warning border-warning">
                                <div class="form-group">
                                    <label class="font-weight-bold">Fecha de ejecución:</label>
                                    <input type="date" name="cron_date" id="cron_date" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label class="font-weight-bold">Hora (0-23):</label>
                                    <input type="number" name="cron_hour" id="cron_hour" class="form-control" min="0" max="23" value="02">
                                </div>
                            </div>

                            <button type="button" id="btn-execute" class="btn btn-block font-weight-bold" style="background-color: #611232 !important;">
                                <i class="fa fa-trash" style="color: white !important;"></i> <span id="btn-text" style="color: white">Iniciar simulación</span>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm" style="border-color: #611232 !important;">
                    <div class="card-header bg-light font-weight-bold">
                        <i class="fa fa-clock-o"></i> Tareas de borrado programadas
                    </div>
                    <div class="card-body">
                        <?php if (!empty($current_period)): ?>
                            <div class="alert alert-info mb-0">
                                <p class="mb-1"><strong>Periodo:</strong> <?php echo s($current_period); ?></p>
                                <p class="mb-1"><strong>Ejecución:</strong> <?php echo s($current_date); ?> a las <?php echo s($current_hour); ?>:00 hrs.</p>
                                <hr>
                                <a href="<?php echo new moodle_url('/local/versionamiento_de_aulas/admin_delete_courses.php', ['action' => 'cancel', 'sesskey' => sesskey()]); ?>"
                                   class="btn btn-danger btn-sm btn-block mt-2"
                                   onclick="return confirm('¿Está seguro de que desea cancelar y eliminar esta programación?');">
                                    <i class="fa fa-times-circle"></i> Cancelar programación
                                </a>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0 italic">No hay tareas de borrado real programadas actualmente.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div id="console-card" style="display:none;" class="card bg-dark shadow-lg">
                    <div class="card-header border-secondary text-success font-weight-bold d-flex justify-content-between">
                        <span>> CONSOLA DE OPERACIONES</span>
                        <small id="status-tag" class="badge badge-secondary">Listo</small>
                    </div>
                    <div id="exec-console" class="card-body" style="height: 500px; overflow-y: auto; background: #000; color: #00ff00; font-family: 'Courier New', monospace; font-size: 0.9rem; border: 1px solid #333;"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const hasActiveTask = <?php echo !empty($current_period) ? 'true' : 'false'; ?>;

        function toggleCronFields() {
            const isDryRun = document.getElementById('dryrun_check').checked;
            document.getElementById('cron_execution_fields').style.display = isDryRun ? 'none' : 'block';
            document.getElementById('btn-text').innerText = isDryRun ? 'Iniciar simulación' : 'Programar eliminación';
        }

        document.getElementById('btn-execute').addEventListener('click', function() {
            const periodo = document.getElementById('periodo_select').value;
            const isDryRun = document.getElementById('dryrun_check').checked;

            if(!periodo) return alert("Seleccione un periodo.");

            const formData = new FormData();
            formData.append('periodo', periodo);
            formData.append('dryrun', isDryRun ? 1 : 0);

            if (!isDryRun) {
                if (hasActiveTask) {
                    return alert("Ya hay una tarea programada actualmente.");
                }

                const cDate = document.getElementById('cron_date').value;
                const cHour = document.getElementById('cron_hour').value;

                if (!cDate) return alert("Debe seleccionar una fecha.");

                formData.append('cron_date', cDate);
                formData.append('cron_hour', cHour);

                if (!confirm("Se programará la ELIMINACIÓN REAL. ¿Continuar?")) return;
            }

            document.getElementById('console-card').style.display = 'block';
            const consoleBox = document.getElementById('exec-console');
            const statusTag = document.getElementById('status-tag');
            consoleBox.innerHTML = '<div>[SISTEMA] Iniciando...</div>';
            statusTag.innerHTML = 'PROCESANDO';
            statusTag.className = 'badge badge-warning';

            fetch('process_delete.php', { method: 'POST', body: formData })
                .then(response => {
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    function read() {
                        return reader.read().then(({ done, value }) => {
                            if (done) {
                                statusTag.innerHTML = 'FINALIZADO';
                                statusTag.className = 'badge badge-success';
                                if(!isDryRun) { setTimeout(() => { location.reload(); }, 2000); }
                                return;
                            }
                            consoleBox.innerHTML += decoder.decode(value);
                            consoleBox.scrollTop = consoleBox.scrollHeight;
                            return read();
                        });
                    }
                    return read();
                });
        });
    </script>
<?php echo $OUTPUT->footer(); ?>