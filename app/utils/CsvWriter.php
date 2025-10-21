<?php
namespace App\Utils;

class CsvWriter
{
    /**
     * Escribe un array de filas en un CSV con cabeceras uniformes.
     *
     * @param string      $filename Nombre del archivo (sin ruta).
     * @param array       $rows     Array de arrays asociativos.
     * @param array|null  $headers  Cabeceras opcionales (si no, se usan las claves del primer row).
     * @param string|null $dir      Directorio opcional (si no, usa DRYRUN_DIR de .env).
     *
     * @return string Ruta completa del archivo generado.
     */
    public static function writeRows(string $filename, array $rows, ?array $headers = null, ?string $dir = null): string
    {
        // Directorio base desde .env
        $dir = $dir
            ?? Config::get('DRYRUN_DIR')   // definido en .env
            ?? Config::dryrunDir();        // fallback

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create directory $dir");
            }
        }

        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open $path for writing");
        }

        if (!empty($rows)) {
            // Determinar cabeceras
            $headers = $headers ?? array_keys($rows[0]);
            fputcsv($fh, $headers);

            foreach ($rows as $r) {
                $line = [];
                foreach ($headers as $col) {
                    $line[] = $r[$col] ?? '';
                }
                fputcsv($fh, $line);
            }
        } else {
            // CSV vacío con solo cabeceras si se pasan explícitas
            if ($headers !== null) {
                fputcsv($fh, $headers);
            }
        }

        fclose($fh);
        return $path;
    }
}