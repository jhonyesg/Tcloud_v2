<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use PDO;
use PDOException;

class PostgresAdminController extends Controller
{
    private function getPgConfig(?Request $request = null): array
    {
        return [
            'host'     => $request?->input('host')     ?: config('database.connections.pgsql.host'),
            'port'     => $request?->input('port')     ?: config('database.connections.pgsql.port'),
            'database' => $request?->input('database') ?: config('database.connections.pgsql.database'),
            'username' => $request?->input('username') ?: config('database.connections.pgsql.username'),
            'password' => $request?->input('password') ?: config('database.connections.pgsql.password'),
        ];
    }

    private function getPdoConnection(array $cfg): PDO
    {
        $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']}";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        return $pdo;
    }

    public function index()
    {
        return view('admin.postgres');
    }

    public function saveConfig(Request $request)
    {
        $validated = $request->validate([
            'host'     => 'required|string',
            'port'     => 'required|string',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        $keys = [
            'DB_HOST'     => $validated['host'],
            'DB_PORT'     => $validated['port'],
            'DB_DATABASE' => $validated['database'],
            'DB_USERNAME' => $validated['username'],
            'DB_PASSWORD' => $validated['password'],
        ];

        foreach ($keys as $key => $value) {
            if (preg_match("/^{$key}=.*$/m", $envContent)) {
                $envContent = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        file_put_contents($envPath, $envContent);

        return response()->json(['success' => true, 'message' => 'Configuración guardada']);
    }

    public function testConnection(Request $request)
    {
        $cfg = $this->getPgConfig($request);

        try {
            $pdo = $this->getPdoConnection($cfg);
            $pdo->query('SELECT 1');
            return response()->json(['success' => true, 'message' => 'Conexión exitosa a PostgreSQL']);
        } catch (PDOException $e) {
            return response()->json(['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()]);
        }
    }

    public function getSchema(Request $request)
    {
        $cfg = $this->getPgConfig($request);

        try {
            $pdo = $this->getPdoConnection($cfg);

            $tablesStmt = $pdo->query("
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = 'public'
                AND table_type = 'BASE TABLE'
                ORDER BY table_name
            ");

            $tables = [];
            foreach ($tablesStmt->fetchAll() as $row) {
                $tableName = $row['table_name'];

                $colsStmt = $pdo->prepare("
                    SELECT column_name, data_type, is_nullable, column_default, character_maximum_length
                    FROM information_schema.columns
                    WHERE table_name = ? AND table_schema = 'public'
                    ORDER BY ordinal_position
                ");
                $colsStmt->execute([$tableName]);

                $columns = [];
                foreach ($colsStmt->fetchAll() as $col) {
                    $columns[] = [
                        'name'      => $col['column_name'],
                        'type'      => $col['data_type'],
                        'nullable'  => $col['is_nullable'] === 'YES',
                        'default'   => $col['column_default'],
                        'maxLength' => $col['character_maximum_length'],
                    ];
                }

                $fkStmt = $pdo->prepare("
                    SELECT kcu.column_name, ccu.table_name AS foreign_table_name, ccu.column_name AS foreign_column_name
                    FROM information_schema.table_constraints AS tc
                    JOIN information_schema.key_column_usage AS kcu
                        ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                    JOIN information_schema.constraint_column_usage AS ccu
                        ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                    WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND tc.table_name = ?
                    AND tc.table_schema = 'public'
                ");
                $fkStmt->execute([$tableName]);

                $foreignKeys = [];
                foreach ($fkStmt->fetchAll() as $fk) {
                    $foreignKeys[] = [
                        'column'     => $fk['column_name'],
                        'references' => $fk['foreign_table_name'] . '.' . $fk['foreign_column_name'],
                    ];
                }

                $tables[] = [
                    'name'        => $tableName,
                    'columns'     => $columns,
                    'foreignKeys' => $foreignKeys,
                ];
            }

            return response()->json(['success' => true, 'tables' => $tables]);

        } catch (PDOException $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener esquema: ' . $e->getMessage()], 500);
        }
    }

    public function executeQuery(Request $request)
    {
        $cfg = $this->getPgConfig($request);
        $sql = trim($request->input('sql', ''));

        if (empty($sql)) {
            return response()->json(['success' => false, 'message' => 'Query vacío'], 400);
        }

        if (!preg_match('/^\s*(SELECT|WITH)\s/i', $sql)) {
            return response()->json(['success' => false, 'message' => 'Solo se permiten consultas SELECT'], 403);
        }

        try {
            $pdo    = $this->getPdoConnection($cfg);
            $stmt   = $pdo->query($sql);
            $columns = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta      = $stmt->getColumnMeta($i);
                $columns[] = $meta['name'];
            }
            $rows = $stmt->fetchAll();

            return response()->json([
                'success'  => true,
                'columns'  => $columns,
                'rows'     => $rows,
                'rowCount' => count($rows),
            ]);

        } catch (PDOException $e) {
            return response()->json(['success' => false, 'message' => 'Error en query: ' . $e->getMessage()], 400);
        }
    }

    public function backupLocal(Request $request)
    {
        $cfg = $this->getPgConfig($request);

        try {
            $pdo = $this->getPdoConnection($cfg);
        } catch (PDOException $e) {
            return response()->json(['success' => false, 'message' => 'Sin conexión a PostgreSQL: ' . $e->getMessage()], 500);
        }

        $filename = "backup_{$cfg['database']}_" . date('Y-m-d_H-i-s') . ".sql";
        $lines    = [];

        $lines[] = "-- Backup generado por Tcloud PostgreSQL Admin";
        $lines[] = "-- Base de datos: {$cfg['database']}";
        $lines[] = "-- Fecha: " . date('Y-m-d H:i:s');
        $lines[] = "-- ============================================";
        $lines[] = "";
        $lines[] = "SET client_encoding = 'UTF8';";
        $lines[] = "SET standard_conforming_strings = on;";
        $lines[] = "BEGIN;";
        $lines[] = "";

        $tablesStmt = $pdo->query("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ");
        $tables = array_column($tablesStmt->fetchAll(), 'table_name');

        foreach ($tables as $tableName) {
            $lines[] = "";
            $lines[] = "-- ──────────────────────────────────────────";
            $lines[] = "-- Table: {$tableName}";
            $lines[] = "-- ──────────────────────────────────────────";
            $lines[] = "DROP TABLE IF EXISTS \"{$tableName}\" CASCADE;";

            $colsStmt = $pdo->prepare("
                SELECT column_name, data_type, is_nullable, column_default,
                       character_maximum_length, numeric_precision, numeric_scale
                FROM information_schema.columns
                WHERE table_name = ? AND table_schema = 'public'
                ORDER BY ordinal_position
            ");
            $colsStmt->execute([$tableName]);

            $colDefs = [];
            foreach ($colsStmt->fetchAll() as $col) {
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

            $pkStmt = $pdo->prepare("
                SELECT kcu.column_name
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                WHERE tc.table_name = ? AND tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
                ORDER BY kcu.ordinal_position
            ");
            $pkStmt->execute([$tableName]);
            $pkCols = array_map(fn($r) => '"' . $r['column_name'] . '"', $pkStmt->fetchAll());
            if (!empty($pkCols)) {
                $colDefs[] = "    PRIMARY KEY (" . implode(', ', $pkCols) . ")";
            }

            $lines[] = "CREATE TABLE \"{$tableName}\" (";
            $lines[] = implode(",\n", $colDefs);
            $lines[] = ");";

            $idxStmt = $pdo->prepare("
                SELECT indexname, indexdef FROM pg_indexes
                WHERE tablename = ? AND schemaname = 'public'
                AND indexname NOT LIKE '%_pkey'
            ");
            $idxStmt->execute([$tableName]);
            foreach ($idxStmt->fetchAll() as $idx) {
                $lines[] = $idx['indexdef'] . ";";
            }

            $dataStmt = $pdo->query("SELECT * FROM \"{$tableName}\"");
            $rows     = $dataStmt->fetchAll();
            if (!empty($rows)) {
                $fieldNames = array_map(fn($k) => '"' . $k . '"', array_keys($rows[0]));
                $lines[] = "";
                $lines[] = "-- Data (" . count($rows) . " rows)";
                foreach ($rows as $dataRow) {
                    $values = [];
                    foreach ($dataRow as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($value) && !preg_match('/^0\d/', $value)) {
                            $values[] = $value;
                        } else {
                            $values[] = "'" . str_replace("'", "''", $value) . "'";
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
            $fkStmt = $pdo->prepare("
                SELECT tc.constraint_name, kcu.column_name,
                       ccu.table_name AS foreign_table, ccu.column_name AS foreign_column
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.constraint_column_usage AS ccu
                    ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ? AND tc.table_schema = 'public'
            ");
            $fkStmt->execute([$tableName]);
            foreach ($fkStmt->fetchAll() as $fk) {
                $lines[] = "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$fk['constraint_name']}\"";
                $lines[] = "    FOREIGN KEY (\"{$fk['column_name']}\") REFERENCES \"{$fk['foreign_table']}\" (\"{$fk['foreign_column']}\");";
            }
        }

        $lines[] = "";
        $lines[] = "COMMIT;";

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
            'ftp_host'     => 'required|string',
            'ftp_port'     => 'required|string',
            'ftp_username' => 'required|string',
            'ftp_password' => 'required|string',
            'ftp_path'     => 'nullable|string',
        ]);

        $envPath    = base_path('.env');
        $envContent = file_get_contents($envPath);

        $keys = [
            'FTP_HOST'     => $validated['ftp_host'],
            'FTP_PORT'     => $validated['ftp_port'],
            'FTP_USERNAME' => $validated['ftp_username'],
            'FTP_PASSWORD' => $validated['ftp_password'],
            'FTP_PATH'     => $validated['ftp_path'] ?? '/',
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
        $cfg = $this->getPgConfig($request);

        $ftpHost = env('FTP_HOST');
        $ftpPort = env('FTP_PORT', '21');
        $ftpUser = env('FTP_USERNAME');
        $ftpPass = env('FTP_PASSWORD');
        $ftpPath = env('FTP_PATH', '/');

        if (!$ftpHost || !$ftpUser || !$ftpPass) {
            return response()->json(['success' => false, 'message' => 'Configuración FTP no encontrada. Guarda la configuración primero.'], 400);
        }

        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename  = "backup_{$cfg['database']}_" . date('Y-m-d_H-i-s') . ".sql";
        $localPath = "{$backupDir}/{$filename}";

        putenv("PGPASSWORD={$cfg['password']}");
        $command    = "pg_dump -h {$cfg['host']} -p {$cfg['port']} -U {$cfg['username']} -d {$cfg['database']} -f " . escapeshellarg($localPath);
        $output     = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($localPath)) {
            return response()->json(['success' => false, 'message' => 'Error creando backup local'], 500);
        }

        $conn = ftp_connect($ftpHost, (int) $ftpPort);
        if (!$conn) {
            return response()->json(['success' => false, 'message' => 'No se pudo conectar al servidor FTP'], 500);
        }

        if (!ftp_login($conn, $ftpUser, $ftpPass)) {
            ftp_close($conn);
            return response()->json(['success' => false, 'message' => 'Credenciales FTP inválidas'], 401);
        }

        ftp_pasv($conn, true);

        if (!empty($ftpPath) && $ftpPath !== '/') {
            ftp_chdir($conn, $ftpPath);
        }

        $upload = ftp_put($conn, $filename, $localPath, FTP_BINARY);
        ftp_close($conn);
        unlink($localPath);

        if ($upload) {
            return response()->json(['success' => true, 'message' => "Backup enviado a FTP exitosamente: {$filename}"]);
        }

        return response()->json(['success' => false, 'message' => 'Error subiendo archivo a FTP'], 500);
    }
}
