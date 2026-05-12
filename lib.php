<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Obtiene IDs de roles permitidos para solicitar/usar respaldos.
 *
 * @return int[]
 */
function local_versionamiento_de_aulas_get_allowed_role_ids(): array {
    $raw = trim((string)get_config('local_versionamiento_de_aulas', 'allowed_role_ids'));
    if ($raw === '') {
        return [10];
    }

    $parts = preg_split('/[\s,;]+/', $raw);
    $ids = [];
    foreach ($parts as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    $ids = array_values(array_unique($ids));
    return empty($ids) ? [10] : $ids;
}

/**
 * Verifica si un usuario tiene alguno de los roles configurados en un contexto.
 *
 * @param int $userid
 * @param int $contextid
 * @return bool
 */
function local_versionamiento_de_aulas_user_has_allowed_role(int $userid, int $contextid): bool {
    $roleids = local_versionamiento_de_aulas_get_allowed_role_ids();
    foreach ($roleids as $roleid) {
        if (user_has_role_assignment($userid, (int)$roleid, $contextid)) {
            return true;
        }
    }
    return false;
}

/**
 * Inyecta el acceso al versionamiento automáticamente en el menú de navegación del curso.
 */
function local_versionamiento_de_aulas_extend_navigation_course($navigation, $course, $context) {
    global $USER, $DB;

    // Verificamos si el usuario es DL en este curso específico
    if (local_versionamiento_de_aulas_user_has_allowed_role((int)$USER->id, (int)$context->id)) {

        // CORRECCIÓN: Se añade el parámetro 'id' del curso actual a la URL
        $url = new moodle_url('/local/versionamiento_de_aulas/index.php', array('id' => $course->id));

        $navigation->add(
            'Reutilización del aula',
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'versionamiento_dl',
            new pix_icon('i/backup', '')
        );
    }
}

/**
 * Sirve los archivos de respaldo MBZ.
 */
function local_versionamiento_de_aulas_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    if ($context->contextlevel != CONTEXT_SYSTEM && $context->contextlevel != CONTEXT_COURSE) {
        return false;
    }
    require_login();
    if ($filearea !== 'backup') {
        return false;
    }
    $fileid = array_shift($args);
    $fs = get_file_storage();
    $file = $fs->get_file_by_id($fileid);
    if (!$file) return false;
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Determina si un nombre de archivo corresponde a un respaldo comprimido con Zstandard.
 *
 * @param string $filename
 * @return bool
 */
function local_versionamiento_de_aulas_is_zst_filename(string $filename): bool {
    return (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zst');
}

/**
 * Valida si el formato del curso coincide con el formato esperado para restauración.
 *
 * @param int $courseid
 * @return array{matches:bool,current:string,expected:string}
 */
function local_versionamiento_de_aulas_validate_restore_course_format(int $courseid): array {
    global $DB;

    $course = $DB->get_record('course', ['id' => $courseid], 'id, format', MUST_EXIST);
    $current = trim((string)$course->format);
    $expected = trim((string)get_config('local_versionamiento_de_aulas', 'expected_course_format'));
    if ($expected === '') {
        $expected = 'buttons';
    }

    $normalize = static function(string $format): string {
        $value = strtolower(trim($format));
        if ($value === 'formato de botones' || $value === 'formato botones' || $value === 'buttons format') {
            return 'buttons';
        }
        if ($value === 'format_buttons') {
            return 'buttons';
        }
        return $value;
    };

    $currentnormalized = $normalize($current);
    $expectednormalized = $normalize($expected);

    return [
        'matches' => ($currentnormalized === $expectednormalized),
        'current' => $current,
        'expected' => $expectednormalized,
    ];
}

/**
 * Callback de configuración: reinicia la fecha de referencia para disponibilidad.
 */
function local_versionamiento_de_aulas_update_retention_reference(): void {
    set_config('retention_configured_at', time(), 'local_versionamiento_de_aulas');
}

/**
 * Obtiene el timestamp base de disponibilidad (fecha en que se configuró el tiempo).
 *
 * @return int
 */
function local_versionamiento_de_aulas_get_retention_reference_timestamp(): int {
    $configuredat = (int)get_config('local_versionamiento_de_aulas', 'retention_configured_at');
    if ($configuredat > 0) {
        return $configuredat;
    }

    $fallback = time();
    set_config('retention_configured_at', $fallback, 'local_versionamiento_de_aulas');
    return $fallback;
}

/**
 * Calcula la fecha de expiración según valor/unidad de configuración (incluye meses y años).
 *
 * @param int $basetimestamp
 * @return int
 */
function local_versionamiento_de_aulas_calculate_retention_expiration(int $basetimestamp): int {
    $value = (int)get_config('local_versionamiento_de_aulas', 'retention_value');
    $unit = trim((string)get_config('local_versionamiento_de_aulas', 'retention_unit'));

    if ($value <= 0) {
        $value = 30;
    }
    if ($unit === '') {
        $unit = 'day';
    }

    $allowed = [
        'second' => 'S',
        'minute' => 'M',
        'hour' => 'H',
        'day' => 'D',
        'week' => 'W',
        'month' => 'MTH',
        'year' => 'Y',
    ];
    if (!array_key_exists($unit, $allowed)) {
        $unit = 'day';
    }

    $base = new \DateTimeImmutable('@' . $basetimestamp);
    $base = $base->setTimezone(new \DateTimeZone(date_default_timezone_get()));

    switch ($unit) {
        case 'second':
            $expires = $base->modify('+' . $value . ' seconds');
            break;
        case 'minute':
            $expires = $base->modify('+' . $value . ' minutes');
            break;
        case 'hour':
            $expires = $base->modify('+' . $value . ' hours');
            break;
        case 'day':
            $expires = $base->modify('+' . $value . ' days');
            break;
        case 'week':
            $expires = $base->modify('+' . $value . ' weeks');
            break;
        case 'month':
            $expires = $base->modify('+' . $value . ' months');
            break;
        case 'year':
            $expires = $base->modify('+' . $value . ' years');
            break;
        default:
            $expires = $base->modify('+30 days');
            break;
    }

    return (int)$expires->getTimestamp();
}

/**
 * Comprime un respaldo MBZ con /bin/zstd a nivel 20 y multihilo automático.
 *
 * @param string $mbzpath Ruta absoluta al archivo .mbz.
 * @return string Ruta absoluta del archivo .zst generado.
 * @throws moodle_exception Si falla la compresión.
 */
function local_versionamiento_de_aulas_compress_mbz_to_zst(string $mbzpath): string {
    if (!is_file($mbzpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo MBZ no encontrado para compresión.');
    }

    $zstpath = $mbzpath . '.zst';
    $command = '/bin/zstd -20 -T0 --force --keep ' . escapeshellarg($mbzpath) . ' 2>&1';
    exec($command, $output, $returncode);

    if ($returncode !== 0 || !is_file($zstpath)) {
        throw new moodle_exception('errorzstdcompression', 'local_versionamiento_de_aulas', '', implode("\n", $output));
    }

    return $zstpath;
}

/**
 * Devuelve el archivo de respaldo listo para extraer (MBZ), descomprimiendo ZST si corresponde.
 *
 * @param string $archivepath Ruta absoluta del archivo fuente (.mbz o .zst).
 * @return string Ruta absoluta del archivo .mbz listo para restaurar.
 * @throws moodle_exception Si falla la descompresión.
 */
function local_versionamiento_de_aulas_prepare_backup_archive(string $archivepath): string {
    if (!is_file($archivepath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo de resguardo no encontrado.');
    }

    if (!local_versionamiento_de_aulas_is_zst_filename($archivepath)) {
        return $archivepath;
    }

    $mbzpath = preg_replace('/\.zst$/i', '', $archivepath);
    if (empty($mbzpath)) {
        throw new moodle_exception('errorzstddecompression', 'local_versionamiento_de_aulas');
    }

    $command = '/bin/zstd -d --force -o ' . escapeshellarg($mbzpath) . ' ' . escapeshellarg($archivepath) . ' 2>&1';
    exec($command, $output, $returncode);

    if ($returncode !== 0 || !is_file($mbzpath)) {
        throw new moodle_exception('errorzstddecompression', 'local_versionamiento_de_aulas', '', implode("\n", $output));
    }

    return $mbzpath;
}


/**
 * Busca la ruta de un binario del sistema.
 *
 * @param array $candidates
 * @return string
 * @throws moodle_exception
 */
function local_versionamiento_de_aulas_find_system_binary(array $candidates): string {
    foreach ($candidates as $candidate) {
        if (!empty($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    foreach ($candidates as $candidate) {
        if (empty($candidate)) {
            continue;
        }
        $name = basename($candidate);
        $resolved = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if (!empty($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }

    throw new moodle_exception('errorrepositorytransport', 'local_versionamiento_de_aulas', '', implode(', ', $candidates));
}

/**
 * Copia un respaldo .zst al repositorio configurado (local o remoto por SSH/SCP).
 *
 * @param string $zstpath Ruta local absoluta del archivo .zst.
 * @throws moodle_exception Si falla la conexión/copia al repositorio.
 */
function local_versionamiento_de_aulas_copy_to_repository(string $zstpath): void {
    if (!is_file($zstpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo ZST no encontrado para copiar al repositorio.');
    }

    $host = trim((string)get_config('local_versionamiento_de_aulas', 'repository_host'));
    $targetdir = trim((string)get_config('local_versionamiento_de_aulas', 'repository_path'));
    $username = trim((string)get_config('local_versionamiento_de_aulas', 'repository_user'));
    $port = (int)get_config('local_versionamiento_de_aulas', 'repository_port');
    $authmethod = trim((string)get_config('local_versionamiento_de_aulas', 'repository_auth_method')) ?: 'password';
    $password = (string)get_config('local_versionamiento_de_aulas', 'repository_password');
    $privatekey = trim((string)get_config('local_versionamiento_de_aulas', 'repository_private_key'));

    if (empty($host)) {
        throw new moodle_exception('invalidrepositoryhost', 'local_versionamiento_de_aulas');
    }
    if (empty($targetdir)) {
        throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas');
    }
    $targetdir = rtrim($targetdir, '/');
    if ($targetdir === '') {
        $targetdir = '/';
    }

    if ($port <= 0) {
        $port = 22;
    }

    if (!in_array($authmethod, ['password', 'key'], true)) {
        $authmethod = 'password';
    }

    $localhosts = ['localhost', '127.0.0.1', '::1'];
    $islocal = in_array(strtolower($host), $localhosts, true);

    if (!$islocal && empty($username)) {
        throw new moodle_exception('invalidrepositoryuser', 'local_versionamiento_de_aulas');
    }

    if ($islocal) {
        if (substr($targetdir, -1) !== '/' && substr($targetdir, -1) !== DIRECTORY_SEPARATOR) {
            $targetdir .= DIRECTORY_SEPARATOR;
        }
        if (!is_dir($targetdir) && !mkdir($targetdir, 0770, true)) {
            throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas', '', $targetdir);
        }
        if (!is_writable($targetdir)) {
            throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas', '', $targetdir);
        }
        $destination = $targetdir . basename($zstpath);
        if (!copy($zstpath, $destination)) {
            throw new moodle_exception('errorrepositorycopy', 'local_versionamiento_de_aulas', '', $destination);
        }
        return;
    }

    $sshbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/ssh', '/bin/ssh']);
    $scpbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/scp', '/bin/scp']);

    $sshauthopts = '';
    $scpauthopts = '';
    $usepass = false;

    if ($authmethod === 'key') {
        if (empty($privatekey) || !is_readable($privatekey)) {
            throw new moodle_exception('invalidrepositorykey', 'local_versionamiento_de_aulas', '', $privatekey);
        }
        $keyopt = '-i ' . escapeshellarg($privatekey);
        $sshauthopts = $keyopt;
        $scpauthopts = $keyopt;
    } else {
        if (empty($password)) {
            throw new moodle_exception('invalidrepositorypassword', 'local_versionamiento_de_aulas');
        }
        $sshpassbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/sshpass', '/bin/sshpass']);
        putenv('SSHPASS=' . $password);
        $usepass = true;
    }

    $usertarget = escapeshellarg($username . '@' . $host);
    $remotecommand = 'mkdir -p ' . escapeshellarg($targetdir);
    $sshopts = '-o ConnectTimeout=15 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o GlobalKnownHostsFile=/dev/null -o UpdateHostKeys=no -o LogLevel=ERROR -F /dev/null';

    // Evita que ssh/scp intenten crear ~/.ssh en cuentas de servicio sin HOME escribible (p.ej. apache/httpd).
    $originalhome = getenv('HOME');
    $temphome = make_request_directory('local_versionamiento_de_aulas_ssh_home');
    putenv('HOME=' . $temphome);

    $mkdircmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $sshbin . ' ' . $sshauthopts .
        ' -p ' . (int)$port .
        ' ' . $sshopts . ' ' .
        $usertarget . ' ' . escapeshellarg($remotecommand) . ' 2>&1';

    exec($mkdircmd, $mkdirout, $mkdircode);
    if ($mkdircode !== 0) {
        if ($usepass) {
            putenv('SSHPASS');
        }
        if ($originalhome !== false) {
            putenv('HOME=' . $originalhome);
        } else {
            putenv('HOME');
        }
        throw new moodle_exception('errorrepositoryconnect', 'local_versionamiento_de_aulas', '', implode("\n", $mkdirout));
    }

    $remote = escapeshellarg($username . '@' . $host . ':' . $targetdir . '/');
    $scpcmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $scpbin . ' ' . $scpauthopts .
        ' -P ' . (int)$port .
        ' ' . $sshopts . ' ' .
        escapeshellarg($zstpath) . ' ' . $remote . ' 2>&1';

    exec($scpcmd, $scout, $sccode);
    if ($usepass) {
        putenv('SSHPASS');
    }

    if ($originalhome !== false) {
        putenv('HOME=' . $originalhome);
    } else {
        putenv('HOME');
    }

    if ($sccode !== 0) {
        $rawerror = implode("\n", $scout);

        // Fallback automático: si no hay permisos en la ruta configurada, intentar en HOME remoto.
        if (stripos($rawerror, 'Permission denied') !== false) {
            $fallbackdir = '~/versionamiento_backups';
            $fallbackmkdir = ($usepass ? ($sshpassbin . ' -e ') : '') .
                $sshbin . ' ' . $sshauthopts .
                ' -p ' . (int)$port .
                ' ' . $sshopts . ' ' .
                $usertarget . ' ' . escapeshellarg('mkdir -p ' . $fallbackdir) . ' 2>&1';

            exec($fallbackmkdir, $fallbackmkdirout, $fallbackmkdircode);

            if ($fallbackmkdircode === 0) {
                $fallbackremote = escapeshellarg($username . '@' . $host . ':' . $fallbackdir . '/');
                $fallbackscpcmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
                    $scpbin . ' ' . $scpauthopts .
                    ' -P ' . (int)$port .
                    ' ' . $sshopts . ' ' .
                    escapeshellarg($zstpath) . ' ' . $fallbackremote . ' 2>&1';

                exec($fallbackscpcmd, $fallbackscout, $fallbacksccode);
                if ($fallbacksccode === 0) {
                    return;
                }

                $rawerror .= "\nFallback HOME remoto falló: " . implode("\n", $fallbackscout);
            } else {
                $rawerror .= "\nFallback HOME remoto falló al crear carpeta: " . implode("\n", $fallbackmkdirout);
            }
        }

        throw new moodle_exception('errorrepositorycopy', 'local_versionamiento_de_aulas', '', $rawerror);
    }
}


/**
 * Copia un respaldo .zst a la ruta local configurada.
 *
 * @param string $zstpath Ruta local absoluta del archivo .zst.
 * @throws moodle_exception Si falla la copia local.
 */
function local_versionamiento_de_aulas_copy_to_local_repository(string $zstpath): void {
    if (!is_file($zstpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo ZST no encontrado para copiar al repositorio local.');
    }

    $localpath = trim((string)get_config('local_versionamiento_de_aulas', 'local_repository_path'));
    if (empty($localpath)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas');
    }

    if (substr($localpath, -1) !== '/' && substr($localpath, -1) !== DIRECTORY_SEPARATOR) {
        $localpath .= DIRECTORY_SEPARATOR;
    }

    if (!is_dir($localpath) && !mkdir($localpath, 0770, true)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas', '', $localpath);
    }

    if (!is_writable($localpath)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas', '', $localpath);
    }

    $destination = $localpath . basename($zstpath);
    if (!copy($zstpath, $destination)) {
        throw new moodle_exception('errorlocalrepositorycopy', 'local_versionamiento_de_aulas', '', $destination);
    }
}


/**
 * Elimina un respaldo por nombre desde repositorios configurados (local/remoto).
 *
 * @param string $filename Nombre del archivo (normalmente .zst o .mbz).
 */
function local_versionamiento_de_aulas_delete_from_repositories(string $filename): void {
    if (empty($filename)) {
        return;
    }

    $variants = [$filename];
    if (preg_match('/\.zst$/i', $filename)) {
        $variants[] = preg_replace('/\.zst$/i', '', $filename);
    } else if (preg_match('/\.mbz$/i', $filename)) {
        $variants[] = $filename . '.zst';
    }
    $variants = array_values(array_unique(array_filter($variants)));

    // Borrado local.
    $localpath = trim((string)get_config('local_versionamiento_de_aulas', 'local_repository_path'));
    if (!empty($localpath) && is_dir($localpath)) {
        foreach ($variants as $name) {
            $localfile = rtrim($localpath, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($localfile)) {
                @unlink($localfile);
            }
        }
    }

    $host = trim((string)get_config('local_versionamiento_de_aulas', 'repository_host'));
    $targetdir = trim((string)get_config('local_versionamiento_de_aulas', 'repository_path'));
    $username = trim((string)get_config('local_versionamiento_de_aulas', 'repository_user'));
    $port = (int)get_config('local_versionamiento_de_aulas', 'repository_port');
    $authmethod = trim((string)get_config('local_versionamiento_de_aulas', 'repository_auth_method')) ?: 'password';
    $password = (string)get_config('local_versionamiento_de_aulas', 'repository_password');
    $privatekey = trim((string)get_config('local_versionamiento_de_aulas', 'repository_private_key'));

    if (empty($host) || empty($targetdir)) {
        return;
    }

    $localhosts = ['localhost', '127.0.0.1', '::1'];
    if (in_array(strtolower($host), $localhosts, true)) {
        foreach ($variants as $name) {
            $file = rtrim($targetdir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return;
    }

    if (empty($username)) {
        return;
    }
    if ($port <= 0) {
        $port = 22;
    }

    try {
        $sshbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/ssh', '/bin/ssh']);
    } catch (\Exception $e) {
        return;
    }

    $sshauthopts = '';
    $usepass = false;
    if ($authmethod === 'key') {
        if (empty($privatekey) || !is_readable($privatekey)) {
            return;
        }
        $sshauthopts = '-i ' . escapeshellarg($privatekey);
    } else {
        if (empty($password)) {
            return;
        }
        try {
            $sshpassbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/sshpass', '/bin/sshpass']);
        } catch (\Exception $e) {
            return;
        }
        putenv('SSHPASS=' . $password);
        $usepass = true;
    }

    $sshopts = '-o ConnectTimeout=15 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o GlobalKnownHostsFile=/dev/null -o UpdateHostKeys=no -o LogLevel=ERROR -F /dev/null';
    $usertarget = escapeshellarg($username . '@' . $host);

    $paths = [];
    foreach ($variants as $name) {
        $paths[] = rtrim($targetdir, '/') . '/' . $name;
        $paths[] = '~/versionamiento_backups/' . $name;
    }
    $paths = array_unique($paths);

    $quoted = array_map('escapeshellarg', $paths);
    $rmcmd = 'rm -f ' . implode(' ', $quoted);

    $originalhome = getenv('HOME');
    $temphome = make_request_directory('local_versionamiento_de_aulas_ssh_home_delete');
    putenv('HOME=' . $temphome);

    $command = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $sshbin . ' ' . $sshauthopts .
        ' -p ' . (int)$port .
        ' ' . $sshopts . ' ' .
        $usertarget . ' ' . escapeshellarg($rmcmd) . ' 2>&1';

    exec($command, $output, $returncode);

    if ($usepass) {
        putenv('SSHPASS');
    }
    if ($originalhome !== false) {
        putenv('HOME=' . $originalhome);
    } else {
        putenv('HOME');
    }
}

/**
 * Marca como excluidas del respaldo las secciones del curso cuyo nombre/resumen coincidan.
 *
 * @param \backup_plan $plan
 * @param int $courseid
 * @param array $needles
 * @return int[] IDs de secciones excluidas.
 */
function local_versionamiento_de_aulas_exclude_sections_from_backup(\backup_plan $plan, int $courseid, array $needles = ['planificación', 'planificacion']): array {
    global $DB;

    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,name,summary');
    if (!$sections) {
        return [];
    }

    $matchtext = static function(string $text) use ($needles): bool {
        $value = core_text::strtolower(trim(strip_tags($text)));
        if ($value === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if (mb_stripos($value, core_text::strtolower($needle)) !== false) {
                return true;
            }
        }
        return false;
    };

    $excluded = [];
    foreach ($sections as $section) {
        if (!$matchtext((string)$section->name) && !$matchtext((string)$section->summary)) {
            continue;
        }

        $candidates = [
            'section_' . $section->id . '_included',
            'section_' . $section->id,
        ];
        foreach ($candidates as $settingname) {
            if ($plan->setting_exists($settingname)) {
                $plan->get_setting($settingname)->set_value(0);
                $excluded[] = (int)$section->id;
                break;
            }
        }
    }

    return array_values(array_unique($excluded));
}

/**
 * Obtiene metadatos de la sección de Presentación desde un respaldo extraído.
 * Prioridad: sección 0, luego nombre "Presentación/Presentacion", luego sección 1 y finalmente primera sección temática.
 *
 * @param string $restorepath
 * @return array|null
 */
function local_versionamiento_de_aulas_get_first_backup_section_data(string $restorepath): ?array {
    $files = glob(rtrim($restorepath, '/\\') . '/sections/section_*/section.xml');
    if (!$files) {
        return null;
    }

    $selected = null;
    $sectionzero = null;
    $sectionone = null;
    $firstthematic = null;
    $ispresentation = static function(string $name): bool {
        $value = core_text::strtolower(trim($name));
        return (mb_stripos($value, 'presentación') !== false || mb_stripos($value, 'presentacion') !== false);
    };

    foreach ($files as $file) {
        $xml = @simplexml_load_file($file);
        if (!$xml) {
            continue;
        }
        $number = (int)($xml->number ?? -1);
        if ($number < 0) {
            continue;
        }
        $candidate = [
            'number' => $number,
            'name' => (string)($xml->name ?? ''),
            'summary' => (string)($xml->summary ?? ''),
            'summaryformat' => isset($xml->summaryformat) ? (int)$xml->summaryformat : FORMAT_HTML,
        ];

        if ($number === 0 && $sectionzero === null) {
            $sectionzero = $candidate;
        }
        if ($ispresentation($candidate['name'])) {
            $selected = $candidate;
            if ($number === 0) {
                break;
            }
        }
        if ($number === 1 && $sectionone === null) {
            $sectionone = $candidate;
        }
        if ($number > 0 && ($firstthematic === null || $candidate['number'] < $firstthematic['number'])) {
            $firstthematic = $candidate;
        }
    }

    if ($sectionzero !== null) {
        return $sectionzero;
    }
    if ($selected !== null) {
        return $selected;
    }
    if ($sectionone !== null) {
        return $sectionone;
    }
    return $firstthematic;
}

/**
 * Limpia por completo la sección Presentación del curso destino para sobrescribirla en una fusión.
 * Prioridad: sección 0, luego nombre "Presentación/Presentacion", luego sección 1 y finalmente primera sección temática.
 *
 * @param int $courseid
 * @param array|null $backupsection
 * @return int|null Número de sección limpiada.
 */
function local_versionamiento_de_aulas_prepare_first_section_overwrite(int $courseid, ?array $backupsection): ?int {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    $targets = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,section,name,summary,summaryformat');
    $target = null;
    $sectionzero = null;
    $fallbackone = null;
    $fallbackfirst = null;
    $ispresentation = static function(string $name): bool {
        $value = core_text::strtolower(trim($name));
        return (mb_stripos($value, 'presentación') !== false || mb_stripos($value, 'presentacion') !== false);
    };
    foreach ($targets as $section) {
        if ($fallbackfirst === null) {
            $fallbackfirst = $section;
        }
        if ((int)$section->section === 0 && $sectionzero === null) {
            $sectionzero = $section;
        }
        if ((int)$section->section === 1 && $fallbackone === null) {
            $fallbackone = $section;
        }
        if ($ispresentation((string)$section->name)) {
            $target = $section;
            if ((int)$section->section === 0) {
                break;
            }
        }
    }
    if ($sectionzero !== null) {
        $target = $sectionzero;
    }
    if ($target === null) {
        $target = $fallbackone ?? $fallbackfirst;
    }
    if (empty($target)) {
        return null;
    }

    $modinfo = get_fast_modinfo($courseid);
    $cmids = $modinfo->sections[$target->section] ?? [];
    foreach ($cmids as $cmid) {
        course_delete_module($cmid);
    }

    $updatesection = (object)[
        'id' => $target->id,
        'sequence' => '',
    ];
    if (!empty($backupsection)) {
        $updatesection->name = (string)($backupsection['name'] ?? '');
        $updatesection->summary = (string)($backupsection['summary'] ?? '');
        $updatesection->summaryformat = (int)($backupsection['summaryformat'] ?? FORMAT_HTML);
    }
    $DB->update_record('course_sections', $updatesection);
    rebuild_course_cache($courseid, true);

    return (int)$target->section;
}

/**
 * Normaliza texto para comparaciones flexibles (sin tildes, minúsculas).
 *
 * @param string $value
 * @return string
 */
function local_versionamiento_de_aulas_normalize_text(string $value): string {
    $value = core_text::strtolower(trim($value));
    $from = ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'];
    $to = ['a', 'e', 'i', 'o', 'u', 'u', 'n'];
    return str_replace($from, $to, $value);
}

/**
 * Regla de fusión por nombre de sección.
 *
 * @param string $sectionname
 * @return array|null
 */
function local_versionamiento_de_aulas_get_section_merge_rule(string $sectionname): ?array {
    $name = local_versionamiento_de_aulas_normalize_text($sectionname);

    if ($name === '' || strpos($name, 'presentacion') !== false) {
        return [
            'overwrite_labels' => 2,
            'merge_nonlabels' => false,
        ];
    }

    if (strpos($name, 'planificacion') !== false || strpos($name, 'planeacion') !== false) {
        return [
            'overwrite_labels' => 3,
            'merge_nonlabels' => true,
        ];
    }

    if (preg_match('/^semana\s*([0-9]{1,2})\b/', $name, $matches)) {
        $week = (int)$matches[1];
        if ($week >= 1 && $week <= 10) {
            return [
                'overwrite_labels' => 1,
                'merge_nonlabels' => true,
            ];
        }
    }

    return null;
}

/**
 * Prepara fusión selectiva:
 * - borra primeras etiquetas existentes según regla por sección,
 * - deja intactos recursos existentes no etiqueta.
 *
 * @param int $courseid
 * @return array Estado para aplicar limpieza posterior.
 */
function local_versionamiento_de_aulas_prepare_selective_merge(int $courseid): array {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');

    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,section,name,summary');
    $modinfo = get_fast_modinfo($courseid);
    $rulesbysection = [];

    foreach ($sections as $section) {
        $sectionnum = (int)$section->section;
        if ($sectionnum === 0) {
            $rule = ['overwrite_labels' => 2, 'merge_nonlabels' => false]; // Presentación.
        } else if ($sectionnum === 1) {
            $rule = ['overwrite_labels' => 3, 'merge_nonlabels' => true]; // Planificación.
        } else {
            $rule = null;
        }

        $rulename = trim((string)$section->name);
        if ($rulename === '') {
            $rulename = trim(strip_tags((string)$section->summary));
        }
        if ((int)$section->section === 0 && trim($rulename) === '') {
            $rulename = 'Presentación';
        }
        if ($rule === null) {
            $rule = local_versionamiento_de_aulas_get_section_merge_rule($rulename);
        }
        if ($rule === null) {
            continue;
        }

        $rulesbysection[$sectionnum] = $rule;
        $cmids = $modinfo->sections[$sectionnum] ?? [];

        $labelcmids = [];
        foreach ($cmids as $cmid) {
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            if ($modinfo->cms[$cmid]->modname === 'label') {
                $labelcmids[] = (int)$cmid;
            }
        }

        $todelete = array_slice($labelcmids, 0, (int)$rule['overwrite_labels']);
        foreach ($todelete as $cmid) {
            course_delete_module($cmid);
        }
    }

    rebuild_course_cache($courseid, true);
    $modinfoafter = get_fast_modinfo($courseid);
    $baseline = [];
    foreach (array_keys($rulesbysection) as $sectionnum) {
        $baseline[$sectionnum] = array_values($modinfoafter->sections[$sectionnum] ?? []);
    }

    return [
        'rules' => $rulesbysection,
        'baseline' => $baseline,
    ];
}

/**
 * Limpieza posterior a la restauración para conservar solo lo permitido por sección.
 *
 * @param int $courseid
 * @param array $state
 * @return void
 */
function local_versionamiento_de_aulas_finalize_selective_merge(int $courseid, array $state): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/course/lib.php');

    $rules = $state['rules'] ?? [];
    $baseline = $state['baseline'] ?? [];
    if (empty($rules)) {
        return;
    }

    $modinfo = get_fast_modinfo($courseid);
    $labelsfirstbysection = [];
    foreach ($rules as $sectionnum => $rule) {
        $before = $baseline[$sectionnum] ?? [];
        $current = array_values($modinfo->sections[(int)$sectionnum] ?? []);
        $newcmids = array_values(array_diff($current, $before));
        if (empty($newcmids)) {
            continue;
        }

        $newlabels = [];
        $newnonlabels = [];
        foreach ($newcmids as $cmid) {
            if (!isset($modinfo->cms[$cmid])) {
                continue;
            }
            if ($modinfo->cms[$cmid]->modname === 'label') {
                $newlabels[] = (int)$cmid;
            } else {
                $newnonlabels[] = (int)$cmid;
            }
        }

        $keeplabels = (int)($rule['overwrite_labels'] ?? 0);
        $labelskept = array_slice($newlabels, 0, $keeplabels);
        $labelstodelete = array_slice($newlabels, $keeplabels);
        foreach ($labelstodelete as $cmid) {
            course_delete_module($cmid);
        }

        $mergenonlabels = !empty($rule['merge_nonlabels']);
        if (!$mergenonlabels) {
            foreach ($newnonlabels as $cmid) {
                course_delete_module($cmid);
            }
        }

        if (!empty($labelskept)) {
            $labelsfirstbysection[(int)$sectionnum] = $labelskept;
        }
    }

    if (!empty($labelsfirstbysection)) {
        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id,section,sequence');
        foreach ($labelsfirstbysection as $sectionnum => $labelskept) {
            $sectionrecord = null;
            foreach ($sections as $sectionrow) {
                if ((int)$sectionrow->section === (int)$sectionnum) {
                    $sectionrecord = $sectionrow;
                    break;
                }
            }
            if (!$sectionrecord) {
                continue;
            }

            $sequence = trim((string)$sectionrecord->sequence);
            if ($sequence === '') {
                continue;
            }
            $sequenceids = array_values(array_filter(array_map('intval', explode(',', $sequence))));
            if (empty($sequenceids)) {
                continue;
            }

            $keptatfront = [];
            foreach ($labelskept as $cmid) {
                if (in_array((int)$cmid, $sequenceids, true)) {
                    $keptatfront[] = (int)$cmid;
                }
            }
            if (empty($keptatfront)) {
                continue;
            }

            $remaining = array_values(array_filter($sequenceids, function($cmid) use ($keptatfront) {
                return !in_array((int)$cmid, $keptatfront, true);
            }));
            $newsequence = implode(',', array_merge($keptatfront, $remaining));
            $DB->set_field('course_sections', 'sequence', $newsequence, ['id' => $sectionrecord->id]);
        }
    }

    rebuild_course_cache($courseid, true);
}
