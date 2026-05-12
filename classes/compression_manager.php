<?php
/**
 * Gestión de compresión Zstd para respaldos de Moodle.
 * Ubicación: /local/versionamiento_de_aulas/classes/compression_manager.php
 */

namespace local_versionamiento_de_aulas;

defined('MOODLE_INTERNAL') || die();

class compression_manager {

    /**
     * Obtiene la ruta del binario zstd verificando ubicaciones comunes.
     */
    private static function get_zstd_path() {
        $paths = ['/bin/zstd', '/usr/bin/zstd', '/usr/local/bin/zstd'];
        foreach ($paths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        // Si no se encuentra en rutas fijas, intentamos usar 'which'
        $which = shell_exec('which zstd 2>/dev/null');
        return !empty($which) ? trim($which) : '/usr/bin/zstd';
    }

    /**
     * Comprime un archivo .mbz a .mbz.zst usando zstd del sistema.
     */
    public static function compress_with_zstd($mbzfile) {
        $result = ['success' => false, 'newfile' => '', 'error' => ''];

        if (!file_exists($mbzfile) || !is_readable($mbzfile)) {
            $result['error'] = "Archivo MBZ no encontrado o no legible: " . $mbzfile;
            return $result;
        }

        $zstd_path = self::get_zstd_path();
        $zstfile = $mbzfile . '.zst';

        // Validar nivel de compresión. RHEL 8 puede sufrir con nivel 22 si hay poca RAM.
        // Recomendado: 15-19 para buen balance.
        $level = (int)get_config('local_versionamiento_de_aulas', 'zstd_level') ?: 19;

        $safe_input = escapeshellarg($mbzfile);
        $safe_output = escapeshellarg($zstfile);

        // -T0 usa todos los núcleos disponibles. --rm eliminaría el original (no lo usamos por seguridad).
        $command = "{$zstd_path} -{$level} -T0 -f {$safe_input} -o {$safe_output} 2>&1";

        exec($command, $output, $returncode);

        if ($returncode === 0 && file_exists($zstfile) && filesize($zstfile) > 0) {
            // Ajuste de permisos para RHEL (Apache suele ser el dueño)
            @chmod($zstfile, 0664);

            $result['success'] = true;
            $result['newfile'] = $zstfile;
        } else {
            $err_msg = implode(" ", $output);
            $result['error'] = "Error en zstd (Código $returncode): " . $err_msg;
            error_log("Fallo Zstd en curso: " . $result['error']);
        }

        return $result;
    }

    /**
     * Descomprime un archivo .zst de vuelta a .mbz para su restauración.
     */
    public static function decompress_zstd($zstfile, $destmbz) {
        if (!file_exists($zstfile)) {
            error_log("Zstd Error: No existe el archivo origen $zstfile");
            return false;
        }

        $zstd_path = self::get_zstd_path();
        $safe_input = escapeshellarg($zstfile);
        $safe_output = escapeshellarg($destmbz);

        // Descomprimir forzando sobrescritura si ya existe (-f)
        $command = "{$zstd_path} -d -f {$safe_input} -o {$safe_output} 2>&1";
        exec($command, $output, $returncode);

        if ($returncode === 0 && file_exists($destmbz)) {
            @chmod($destmbz, 0664);
            return true;
        }

        error_log("Zstd Decompress Error: " . implode(" ", $output));
        return false;
    }

    /**
     * Calcula el ratio de ahorro de espacio.
     */
    public static function get_compression_ratio($original, $compressed) {
        if (!file_exists($original) || !file_exists($compressed)) return 0;
        $orig_size = filesize($original);
        $comp_size = filesize($compressed);

        if ($orig_size <= 0) return 0;

        $ratio = round((1 - ($comp_size / $orig_size)) * 100, 2);
        return max(0, $ratio); // Evitar negativos en archivos ya comprimidos
    }
}