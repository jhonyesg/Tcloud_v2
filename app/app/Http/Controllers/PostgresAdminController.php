<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

class PostgresAdminController extends Controller
{
    private $pgConnection = null;

    private function getPgConnection()
    {
        if ($this->pgConnection !== null) {
            return $this->pgConnection;
        }

        $host = env('PG_HOST', 'postgres');
        $port = env('PG_PORT', '5432');
        $database = env('PG_DATABASE', 'tcloud');
        $username = env('PG_USERNAME', 'postgres');
        $password = env('PG_PASSWORD', 'postgres');

        try {
            $this->pgConnection = pg_connect(
                "host={$host} port={$port} dbname={$database} user={$username} password={$password}"
            );
        } catch (\Exception $e) {
            $this->pgConnection = null;
        }

        return $this->pgConnection;
    }

    public function index()
    {
        return view('admin.postgres');
    }

    public function saveConfig(Request $request)
    {
        $validated = $request->validate([
            'pg_host' => 'required|string',
            'pg_port' => 'required|string',
            'pg_database' => 'required|string',
            'pg_username' => 'required|string',
            'pg_password' => 'required|string',
        ]);

        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        $keys = [
            'PG_HOST' => $validated['pg_host'],
            'PG_PORT' => $validated['pg_port'],
            'PG_DATABASE' => $validated['pg_database'],
            'PG_USERNAME' => $validated['pg_username'],
            'PG_PASSWORD' => $validated['pg_password'],
        ];

        foreach ($keys as $key => $value) {
            if (preg_match("/^{$key}=.*$/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);
        putenv("PG_HOST={$validated['pg_host']}");
        putenv("PG_PORT={$validated['pg_port']}");
        putenv("PG_DATABASE={$validated['pg_database']}");
        putenv("PG_USERNAME={$validated['pg_username']}");
        putenv("PG_PASSWORD={$validated['pg_password']}");

        return response()->json(['success' => true, 'message' => 'Configuración guardada']);
    }

    public function testConnection(Request $request)
    {
        $host = $request->input('host') ?: env('PG_HOST', 'postgres');
        $port = $request->input('port') ?: env('PG_PORT', '5432');
        $database = $request->input('database') ?: env('PG_DATABASE', 'tcloud');
        $username = $request->input('username') ?: env('PG_USERNAME', 'postgres');
        $password = $request->input('password') ?: env('PG_PASSWORD', 'postgres');

        try {
            $conn = pg_connect("host={$host} port={$port} dbname={$database} user={$username} password={$password}");
            if ($conn) {
                pg_close($conn);
                return response()->json([
                    'success' => true,
                    'message' => 'Conexión exitosa a PostgreSQL'
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de conexión: ' . $e->getMessage()
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo conectar a PostgreSQL'
        ]);
    }

    public function getSchema(Request $request)
    {
        $host = env('PG_HOST', 'postgres');
        $port = env('PG_PORT', '5432');
        $database = env('PG_DATABASE', 'tcloud');
        $username = env('PG_USERNAME', 'postgres');
        $password = env('PG_PASSWORD', 'postgres');

        try {
            $conn = pg_connect("host={$host} port={$port} dbname={$database} user={$username} password={$password}");
            if (!$conn) {
                return response()->json(['success' => false, 'message' => 'Sin conexión'], 500);
            }

            $tablesResult = pg_query($conn, "
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");

            $tables = [];
            while ($row = pg_fetch_assoc($tablesResult)) {
                $tableName = $row['table_name'];
                
                $columnsResult = pg_query($conn, "
                    SELECT 
                        column_name, 
                        data_type,
                        is_nullable,
                        column_default,
                        character_maximum_length
                    FROM information_schema.columns 
                    WHERE table_name = '{$tableName}' 
                    AND table_schema = 'public'
                    ORDER BY ordinal_position
                ");

                $columns = [];
                while ($col = pg_fetch_assoc($columnsResult)) {
                    $columns[] = [
                        'name' => $col['column_name'],
                        'type' => $col['data_type'],
                        'nullable' => $col['is_nullable'] === 'YES',
                        'default' => $col['column_default'],
                        'maxLength' => $col['character_maximum_length'],
                    ];
                }

                $fkResult = pg_query($conn, "
                    SELECT
                        tc.constraint_name,
                        kcu.column_name,
                        ccu.table_name AS foreign_table_name,
                        ccu.column_name AS foreign_column_name
                    FROM information_schema.table_constraints AS tc
                    JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
                    JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
                    WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_name = '{$tableName}'
                ");

                $foreignKeys = [];
                while ($fk = pg_fetch_assoc($fkResult)) {
                    $foreignKeys[] = [
                        'column' => $fk['column_name'],
                        'references' => $fk['foreign_table_name'] . '.' . $fk['foreign_column_name'],
                    ];
                }

                $tables[] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'foreignKeys' => $foreignKeys,
                ];
            }

            pg_close($conn);

            return response()->json([
                'success' => true,
                'tables' => $tables
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener esquema: ' . $e->getMessage()
            ], 500);
        }
    }

    public function executeQuery(Request $request)
    {
        $host = env('PG_HOST', 'postgres');
        $port = env('PG_PORT', '5432');
        $database = env('PG_DATABASE', 'tcloud');
        $username = env('PG_USERNAME', 'postgres');
        $password = env('PG_PASSWORD', 'postgres');

        $sql = trim($request->input('sql', ''));

        if (empty($sql)) {
            return response()->json([
                'success' => false,
                'message' => 'Query vacío'
            ], 400);
        }

        $isSelect = preg_match('/^\s*(SELECT|WITH)\s/i', $sql);
        $isReadOnly = $isSelect;

        if (!$isReadOnly) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se permiten consultas SELECT'
            ], 403);
        }

        try {
            $conn = pg_connect("host={$host} port={$port} dbname={$database} user={$username} password={$password}");
            if (!$conn) {
                return response()->json(['success' => false, 'message' => 'Sin conexión'], 500);
            }

            $result = pg_query($conn, $sql);
            
            if (!$result) {
                pg_close($conn);
                return response()->json([
                    'success' => false,
                    'message' => 'Error en query: ' . pg_last_error($conn)
                ], 400);
            }

            $columns = [];
            $numFields = pg_num_fields($result);
            for ($i = 0; $i < $numFields; $i++) {
                $columns[] = pg_field_name($result, $i);
            }

            $rows = [];
            while ($row = pg_fetch_assoc($result)) {
                $rows[] = $row;
            }

            pg_close($conn);

            return response()->json([
                'success' => true,
                'columns' => $columns,
                'rows' => $rows,
                'rowCount' => count($rows)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function backupLocal(Request $request)
    {
        $host     = env('PG_HOST',     'postgres');
        $port     = env('PG_PORT',     '5432');
        $database = env('PG_DATABASE', 'tcloud');
        $username = env('PG_USERNAME', 'postgres');
        $password = env('PG_PASSWORD', 'postgres');

        $conn = pg_connect("host={$host} port={$port} dbname={$database} user={$username} password={$password}");
        if (!$conn) {
            return response()->json(['success' => false, 'message' => 'Sin conexión a PostgreSQL'], 500);
        }

        $filename = "backup_{$database}_" . date('Y-m-d_H-i-s') . ".sql";
        $lines    = [];

        $lines[] = "-- Backup generado por Tcloud PostgreSQL Admin";
        $lines[] = "-- Base de datos: {$database}";
        $lines[] = "-- Fecha: " . date('Y-m-d H:i:s');
        $lines[] = "-- ============================================";
        $lines[] = "";
        $lines[] = "SET client_encoding = 'UTF8';";
        $lines[] = "SET standard_conforming_strings = on;";
        $lines[] = "BEGIN;";
        $lines[] = "";

        $tablesResult = pg_query($conn, "
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ");
        $tables = [];
        while ($row = pg_fetch_assoc($tablesResult)) {
            $tables[] = $row['table_name'];
        }

        foreach ($tables as $tableName) {
            $safe = pg_escape_string($conn, $tableName);

            $lines[] = "";
            $lines[] = "-- ──────────────────────────────────────────";
            $lines[] = "-- Table: {$tableName}";
            $lines[] = "-- ──────────────────────────────────────────";
            $lines[] = "DROP TABLE IF EXISTS \"{$tableName}\" CASCADE;";

            $colsResult = pg_query($conn, "
                SELECT column_name, data_type, is_nullable, column_default,
                       character_maximum_length, numeric_precision, numeric_scale
                FROM information_schema.columns
                WHERE table_name = '{$safe}' AND table_schema = 'public'
                ORDER BY ordinal_position
            ");
            $colDefs = [];
            while ($col = pg_fetch_assoc($colsResult)) {
                $type = $col['data_type'];
                if ($col['character_maximum_length']) {
                    $type .= "({$col['character_maximum_length']})";
                } elseif ($col['numeric_precision'] && $col['data_type'] === 'numeric') {
                    $type .= "({$col['numeric_precision']},{$col['numeric_scale']})";
                }
                $def = "    \"{$col['column_name']}\" {$type}";
                if ($col['is_nullable'] === 'NO') $def .= " NOT NULL";
                if ($col['column_default'] !== null) $def .= " DEFAULT {$col['column_default']}";
                $colDefs[] = $def;
            }

            $pkResult = pg_query($conn, "
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name
                WHERE tc.table_name = '{$safe}' AND tc.constraint_type = 'PRIMARY KEY'
                ORDER BY kcu.ordinal_position
            ");
            $pkCols = [];
            while ($pk = pg_fetch_assoc($pkResult)) {
                $pkCols[] = '"' . $pk['column_name'] . '"';
            }
            if (!empty($pkCols)) {
                $colDefs[] = "    PRIMARY KEY (" . implode(', ', $pkCols) . ")";
            }

            $lines[] = "CREATE TABLE \"{$tableName}\" (";
            $lines[] = implode(",\n", $colDefs);
            $lines[] = ");";

            $idxResult = pg_query($conn, "
                SELECT indexname, indexdef FROM pg_indexes
                WHERE tablename = '{$safe}' AND schemaname = 'public'
                AND indexname NOT LIKE '%_pkey'
            ");
            while ($idx = pg_fetch_assoc($idxResult)) {
                $lines[] = $idx['indexdef'] . ";";
            }

            $dataResult = pg_query($conn, "SELECT * FROM \"{$tableName}\"");
            $numFields  = pg_num_fields($dataResult);
            $numRows    = pg_num_rows($dataResult);

            if ($numRows > 0) {
                $fieldNames = [];
                for ($i = 0; $i < $numFields; $i++) {
                    $fieldNames[] = '"' . pg_field_name($dataResult, $i) . '"';
                }
                $lines[] = "";
                $lines[] = "-- Data ({$numRows} rows)";
                while ($dataRow = pg_fetch_assoc($dataResult)) {
                    $values = [];
                    foreach ($dataRow as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($value) && !preg_match('/^0\d/', $value)) {
                            $values[] = $value;
                        } else {
                            $values[] = "'" . pg_escape_string($conn, $value) . "'";
                        }
                    }
                    $lines[] = "INSERT INTO \"{$tableName}\" (" . implode(', ', $fieldNames) . ") VALUES (" . implode(', ', $values) . ");";
                }
            }
        }

        $lines[] = "";
        $lines[] = "-- ──────────────────────────────────────────";
        $lines[] = "-- Foreign Key Constraints";
        $lines[] = "-- ──────────────────────────────────────────";
        foreach ($tables as $tableName) {
            $safe     = pg_escape_string($conn, $tableName);
            $fkResult = pg_query($conn, "
                SELECT tc.constraint_name, kcu.column_name,
                       ccu.table_name AS foreign_table, ccu.column_name AS foreign_column
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = '{$safe}'
            ");
            while ($fk = pg_fetch_assoc($fkResult)) {
                $lines[] = "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$fk['constraint_name']}\"";
                $lines[] = "    FOREIGN KEY (\"{$fk['column_name']}\") REFERENCES \"{$fk['foreign_table']}\" (\"{$fk['foreign_column']}\");";
            }
        }

        $lines[] = "";
        $lines[] = "COMMIT;";

        pg_close($conn);

        $sqlContent = implode("\n", $lines);

        return response($sqlContent, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length'      => strlen($sqlContent),
        ]);
    }

    public function saveFtpConfig(Request $request)
    {
        $validated = $request->validate([
            'ftp_host' => 'required|string',
            'ftp_port' => 'required|string',
            'ftp_username' => 'required|string',
            'ftp_password' => 'required|string',
            'ftp_path' => 'nullable|string',
        ]);

        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        $keys = [
            'FTP_HOST' => $validated['ftp_host'],
            'FTP_PORT' => $validated['ftp_port'],
            'FTP_USERNAME' => $validated['ftp_username'],
            'FTP_PASSWORD' => $validated['ftp_password'],
            'FTP_PATH' => $validated['ftp_path'] ?? '/',
        ];

        foreach ($keys as $key => $value) {
            if (preg_match("/^{$key}=.*$/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);

        return response()->json(['success' => true, 'message' => 'Configuración FTP guardada']);
    }

    public function backupFtp(Request $request)
    {
        $host = env('PG_HOST', 'postgres');
        $port = env('PG_PORT', '5432');
        $database = env('PG_DATABASE', 'tcloud');
        $username = env('PG_USERNAME', 'postgres');
        $password = env('PG_PASSWORD', 'postgres');

        $ftpHost = env('FTP_HOST');
        $ftpPort = env('FTP_PORT', '21');
        $ftpUser = env('FTP_USERNAME');
        $ftpPass = env('FTP_PASSWORD');
        $ftpPath = env('FTP_PATH', '/');

        if (!$ftpHost || !$ftpUser || !$ftpPass) {
            return response()->json([
                'success' => false,
                'message' => 'Configuración FTP no encontrada. Guarda la configuración primero.'
            ], 400);
        }

        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = "backup_{$database}_" . date('Y-m-d_H-i-s') . ".sql";
        $localPath = "{$backupDir}/{$filename}";

        putenv("PGPASSWORD={$password}");
        $command = "pg_dump -h {$host} -p {$port} -U {$username} -d {$database} -f {$localPath}";
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($localPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Error creando backup local'
            ], 500);
        }

        $conn = ftp_connect($ftpHost, (int)$ftpPort);
        if (!$conn) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al servidor FTP'
            ], 500);
        }

        $login = ftp_login($conn, $ftpUser, $ftpPass);
        if (!$login) {
            ftp_close($conn);
            return response()->json([
                'success' => false,
                'message' => 'Credenciales FTP inválidas'
            ], 401);
        }

        ftp_pasv($conn, true);

        if (!empty($ftpPath) && $ftpPath !== '/') {
            ftp_chdir($conn, $ftpPath);
        }

        $upload = ftp_put($conn, $filename, $localPath, FTP_BINARY);
        ftp_close($conn);

        unlink($localPath);

        if ($upload) {
            return response()->json([
                'success' => true,
                'message' => "Backup enviado a FTP exitosamente: {$filename}"
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Error subiendo archivo a FTP'
        ], 500);
    }
}
