<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $DB;

    // 1. CATEGORÍA RAÍZ
    $ADMIN->add('localplugins', new admin_category('local_versionamiento_root', 'Histórico y reutilización de Aulas'));

    // 2. PANEL DE CONTROL E HISTÓRICO
    $ADMIN->add('local_versionamiento_root', new admin_externalpage('local_versionamiento_admin', 'Panel de control', new moodle_url('/local/versionamiento_de_aulas/admin.php')));
    $ADMIN->add('local_versionamiento_root', new admin_externalpage('local_versionamiento_delete', 'Histórico de Aulas', new moodle_url('/local/versionamiento_de_aulas/admin_delete_courses.php'), 'moodle/site:config'));

    // 3. CONFIGURACIÓN DE PERIODOS (ESTO NO SE TOCA)
    $settings_periodos = new admin_settingpage('local_versionamiento_de_aulas_periodos', 'Configuración de periodos');
    $settings_periodos->add(new admin_setting_heading('header_respaldos', 'Periodo para la generación de resguardos', 'Rango de fechas en los que el plugin permitirá solicitar resguardos.'));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/respaldo_inicio', 'Inicio de solicitudes', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/respaldo_fin', 'Fin de solicitudes', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_heading('header_restauracion', 'Periodo para la restauración de aulas', 'Rango de fechas en los que el plugin permitirá restaurar aulas.'));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/restaurar_inicio', 'Inicio de restauración', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/restaurar_fin', 'Fin de restauración', '', '', PARAM_TEXT));
    $ADMIN->add('local_versionamiento_root', $settings_periodos);

    // 4. CONFIGURACIÓN DEL PLUGIN (AQUÍ SÓLO AGREGAMOS LO NECESARIO)
    $settings_tecnico = new admin_settingpage('local_versionamiento_de_aulas', 'Configuración de plugin');

    // Se agregan los campos de programación para que la tarea de respaldos sepa cuándo actuar
    $settings_tecnico->add(new admin_setting_heading('header_cron_respaldos', 'Configuración de ejecución del cron (Resguardos)', 'Define la programación para procesar la cola de resguardos.'));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/backup_cron_date', 'Fecha de ejecución resguardos', 'Formato YYYY-MM-DD', '', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/backup_cron_hour', 'Hora de ejecución resguardos', 'Hora (0-23)', '0', PARAM_INT));

    $options_freq = [
        '1' => 'Cada minuto',
        '5' => 'Cada 5 minutos',
        '15' => 'Cada 15 minutos',
        '30' => 'Cada 30 minutos',
        '60' => 'Cada hora'
    ];
    $settings_tecnico->add(new admin_setting_configselect('local_versionamiento_de_aulas/backup_cron_freq', 'Frecuencia de ejecución del cron', '', '5', $options_freq));

    // Campos originales de limpieza y repositorio
    $settings_tecnico->add(new admin_setting_heading('header_tecnico', 'Configuración Técnica', ''));
    $roleoptions = [];
    $roles = $DB->get_records('role', null, 'sortorder ASC, id ASC', 'id,shortname,name');
    foreach ($roles as $role) {
        $label = trim((string)$role->name);
        if ($label === '') {
            $label = (string)$role->shortname;
        }
        $roleoptions[(int)$role->id] = $label . ' (ID ' . (int)$role->id . ')';
    }
    $settings_tecnico->add(new admin_setting_configmultiselect(
        'local_versionamiento_de_aulas/allowed_role_ids',
        'Roles permitidos para resguardos',
        'Selecciona los roles que pueden solicitar resguardos y reutilizar contenido en el curso.',
        [10],
        $roleoptions
    ));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/expected_course_format', 'Formato de curso esperado para restauración', 'Shortname del formato esperado (ej: buttons para Formato de botones). Si el curso tiene otro formato, se mostrará advertencia pero la restauración continuará.', 'buttons', PARAM_ALPHANUMEXT));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/cron_eliminar_mbz_fecha', 'Fecha para eliminar archivos .mbz', '', '', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/local_repository_path', 'Ruta local de resguardos', 'Ruta local en el servidor Moodle donde se almacenarán los resguardos .zst cuando NO se use repositorio remoto.', '/www/backups/', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configcheckbox('local_versionamiento_de_aulas/use_repository_path', 'Guardar resguardos en repositorio externo', 'Si se habilita, se guardará también una copia comprimida (.zst) en la ruta del repositorio.', 0));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_host', 'Host/IP del repositorio remoto', 'Nombre DNS o dirección IP del host remoto donde reside el repositorio.', '', PARAM_HOST));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_port', 'Puerto SSH del repositorio remoto', 'Puerto para conexión SSH/SCP al host remoto.', '22', PARAM_INT));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_user', 'Usuario remoto', 'Usuario con permisos para crear carpetas y subir resguardos al host remoto.', '', PARAM_USERNAME));
    $settings_tecnico->add(new admin_setting_configselect('local_versionamiento_de_aulas/repository_auth_method', 'Método de autenticación remota', '', 'password', ['password' => 'Usuario/contraseña', 'key' => 'Llave privada SSH']));
    $settings_tecnico->add(new admin_setting_configpasswordunmask('local_versionamiento_de_aulas/repository_password', 'Contraseña remota', 'Contraseña del usuario remoto (solo si usa autenticación por contraseña).', ''));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_private_key', 'Ruta de llave privada SSH', 'Ruta absoluta en el servidor Moodle (solo si usa autenticación por llave). Ej: /home/apache/.ssh/id_rsa', '', PARAM_RAW_TRIMMED));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_path', 'Ruta del repositorio', '', '/www/backups/', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_heading('header_retencion', 'Disponibilidad del resguardo', 'Define el tiempo de disponibilidad contando desde la fecha en que se guarda esta configuración.'));
    $retentionvalue = new admin_setting_configtext(
        'local_versionamiento_de_aulas/retention_value',
        'Tiempo de disponibilidad',
        'Número de unidades de disponibilidad (por ejemplo: 2).',
        '30',
        PARAM_INT
    );
    $retentionvalue->set_updatedcallback('local_versionamiento_de_aulas_update_retention_reference');
    $settings_tecnico->add($retentionvalue);

    $retentionunit = new admin_setting_configselect(
        'local_versionamiento_de_aulas/retention_unit',
        'Unidad de disponibilidad',
        'Unidad del tiempo configurado para disponibilidad.',
        'day',
        [
            'second' => 'Segundos',
            'minute' => 'Minutos',
            'hour' => 'Horas',
            'day' => 'Días',
            'week' => 'Semanas',
            'month' => 'Meses',
            'year' => 'Años',
        ]
    );
    $retentionunit->set_updatedcallback('local_versionamiento_de_aulas_update_retention_reference');
    $settings_tecnico->add($retentionunit);

    $ADMIN->add('local_versionamiento_root', $settings_tecnico);

    // JS para DatePickers
    if (strpos($_SERVER['REQUEST_URI'], 'section=local_versionamiento_') !== false) {
        echo '<script>
            window.onload = function() {
                var dates = ["respaldo_inicio", "respaldo_fin", "restaurar_inicio", "restaurar_fin", "backup_cron_date", "cron_eliminar_mbz_fecha"];
                dates.forEach(function(id) {
                    var input = document.getElementById("id_s_local_versionamiento_de_aulas_" + id);
                    if (input) input.type = "date";
                });
            }
        </script>';
    }
}
