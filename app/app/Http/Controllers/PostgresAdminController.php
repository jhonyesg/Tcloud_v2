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

        // Stream la respuesta: nunca carga toda la BD en memoria
        return response()->streamDownload(function () use ($pdo, $cfg) {
            // Snapshot consistente para todo el backup
            $pdo->exec("BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ");

            echo "-- Backup generado por Tcloud PostgreSQL Admin\n";
            echo "-- Base de datos: {$cfg['database']}\n";
            echo "-- Fecha: " . date('Y-m-d H:i:s') . "\n";
            echo "-- ============================================\n\n";
            echo "SET client_encoding = 'UTF8';\n";
            echo "SET standard_conforming_strings = on;\n";
            echo "BEGIN;\n";
            flush();

            $tables = array_column($pdo->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
                 ORDER BY table_name"
            )->fetchAll(PDO::FETCH_ASSOC), 'table_name');

            foreach ($tables as $tableName) {
                echo "\n-- ──────────────────────────────────────────\n";
                echo "-- Table: {$tableName}\n";
                echo "-- ──────────────────────────────────────────\n";
                echo "DROP TABLE IF EXISTS \"{$tableName}\" CASCADE;\n";

                // Columnas
                $colsStmt = $pdo->prepare(
                    "SELECT column_name, data_type, udt_name, is_nullable, column_default,
                            character_maximum_length, numeric_precision, numeric_scale
                     FROM information_schema.columns
                     WHERE table_name = ? AND table_schema = 'public'
                     ORDER BY ordinal_position"
                );
                $colsStmt->execute([$tableName]);

                $colDefs     = [];
                $columnNames = [];
                foreach ($colsStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    $columnNames[] = '"' . $col['column_name'] . '"';
                    $def = "    \"{$col['column_name']}\" " . $this->resolveColumnType($col);
                    if ($col['is_nullable'] === 'NO') $def .= ' NOT NULL';
                    if ($col['column_default'] !== null) $def .= " DEFAULT {$col['column_default']}";
                    $colDefs[] = $def;
                }

                // Primary key
                $pkStmt = $pdo->prepare(
                    "SELECT kcu.column_name
                     FROM information_schema.table_constraints tc
                     JOIN information_schema.key_column_usage kcu
                         ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                     WHERE tc.table_name = ? AND tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = 'public'
                     ORDER BY kcu.ordinal_position"
                );
                $pkStmt->execute([$tableName]);
                $pkCols = array_map(fn($r) => '"' . $r['column_name'] . '"', $pkStmt->fetchAll(PDO::FETCH_ASSOC));
                if (!empty($pkCols)) {
                    $colDefs[] = '    PRIMARY KEY (' . implode(', ', $pkCols) . ')';
                }

                echo "CREATE TABLE \"{$tableName}\" (\n";
                echo implode(",\n", $colDefs) . "\n);\n";

                // Índices (excluyendo PK)
                $idxStmt = $pdo->prepare(
                    "SELECT indexname, indexdef FROM pg_indexes
                     WHERE tablename = ? AND schemaname = 'public' AND indexname NOT LIKE '%_pkey'"
                );
                $idxStmt->execute([$tableName]);
                foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
                    echo $idx['indexdef'] . ";\n";
                }

                // Datos con cursor — lee 1000 filas a la vez, sin cargar la tabla entera
                $cursorName = 'bkp_' . preg_replace('/[^a-z0-9_]/', '_', $tableName);
                $pdo->exec("DECLARE {$cursorName} CURSOR FOR SELECT * FROM \"{$tableName}\"");

                $firstBatch  = true;
                $insertPrefix = "INSERT INTO \"{$tableName}\" (" . implode(', ', $columnNames) . ') VALUES ';
                while (true) {
                    $batch = $pdo->query("FETCH 1000 FROM {$cursorName}")->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($batch)) break;

                    if ($firstBatch) {
                        echo "\n-- Data\n";
                        $firstBatch = false;
                    }

                    foreach ($batch as $row) {
                        $values = array_map([$this, 'escapeSqlValue'], array_values($row));
                        echo $insertPrefix . '(' . implode(', ', $values) . ");\n";
                    }
                    flush();
                }

                $pdo->exec("CLOSE {$cursorName}");
                flush();
            }

            // Foreign keys con reglas ON DELETE / ON UPDATE
            echo "\n-- ──────────────────────────────────────────\n";
            echo "-- Foreign Key Constraints\n";
            echo "-- ──────────────────────────────────────────\n";
            foreach ($tables as $tableName) {
                $fkStmt = $pdo->prepare(
                    "SELECT tc.constraint_name, kcu.column_name,
                            ccu.table_name AS foreign_table, ccu.column_name AS foreign_column,
                            rc.delete_rule, rc.update_rule
                     FROM information_schema.table_constraints tc
                     JOIN information_schema.key_column_usage kcu
                         ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                     JOIN information_schema.constraint_column_usage ccu
                         ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
                     JOIN information_schema.referential_constraints rc
                         ON tc.constraint_name = rc.constraint_name AND tc.table_schema = rc.constraint_schema
                     WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ? AND tc.table_schema = 'public'"
                );
                $fkStmt->execute([$tableName]);
                foreach ($fkStmt->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                    $actions = '';
                    if ($fk['delete_rule'] !== 'NO ACTION') $actions .= " ON DELETE {$fk['delete_rule']}";
                    if ($fk['update_rule'] !== 'NO ACTION') $actions .= " ON UPDATE {$fk['update_rule']}";
                    echo "ALTER TABLE \"{$tableName}\" ADD CONSTRAINT \"{$fk['constraint_name']}\"\n";
                    echo "    FOREIGN KEY (\"{$fk['column_name']}\") REFERENCES \"{$fk['foreign_table']}\" (\"{$fk['foreign_column']}\"){$actions};\n";
                }
            }

            // CHECK constraints
            echo "\n-- ──────────────────────────────────────────\n";
            echo "-- Check Constraints\n";
            echo "-- ──────────────────────────────────────────\n";
            $ckStmt = $pdo->query(
                "SELECT conrelid::regclass AS tbl, conname, pg_get_constraintdef(oid) AS def
                 FROM pg_constraint WHERE contype = 'c'
                 ORDER BY tbl, conname"
            );
            foreach ($ckStmt->fetchAll(PDO::FETCH_ASSOC) as $ck) {
                echo "ALTER TABLE \"{$ck['tbl']}\" ADD CONSTRAINT \"{$ck['conname']}\" {$ck['def']};\n";
            }

            $pdo->exec('COMMIT');
            echo "\nCOMMIT;\n";
        }, $filename, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Accel-Buffering'   => 'no',
        ]);
    }

    private function resolveColumnType(array $col): string
    {
        $type = $col['data_type'];

        if ($type === 'USER-DEFINED') return $col['udt_name'];
        if ($type === 'ARRAY')        return $col['udt_name'];

        if (!empty($col['character_maximum_length'])) {
            return "character varying({$col['character_maximum_length']})";
        }
        if ($col['data_type'] === 'numeric' && !empty($col['numeric_precision'])) {
            return "numeric({$col['numeric_precision']},{$col['numeric_scale']})";
        }

        return $type;
    }

    private function escapeSqlValue($value): string
    {
        if ($value === null) return 'NULL';
        if ($value === 't')  return 'TRUE';
        if ($value === 'f')  return 'FALSE';

        // Números sin cero inicial (enteros y decimales)
        if (is_numeric($value) && !preg_match('/^0\d/', (string) $value)) {
            return (string) $value;
        }

        // Todo lo demás: escapar comillas simples y backslashes
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $value) . "'";
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
