<?php
declare(strict_types=1);

final class DataTransfer
{
    public function __construct(private PDO $db) {}

    public function export(string $path): void
    {
        $data = ['format' => 'jj-project-data', 'schema_version' => Schema::VERSION, 'exported_at' => gmdate('c'), 'tables' => []];
        $this->db->beginTransaction();
        try {
            foreach (Schema::TABLES as $table) {
                $data['tables'][$table] = $this->db->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
        $file = fopen($path, 'x');
        if (!$file) { throw new RuntimeException('Cannot create export. Choose a new file path.'); }
        try {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($file, $json) !== strlen($json)) { throw new RuntimeException('Export write failed.'); }
        } finally { fclose($file); }
        @chmod($path, 0600);
    }

    public function import(string $path, string $environment): void
    {
        if ($environment !== 'dev') { throw new RuntimeException('Data import is restricted to APP_ENV=dev.'); }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($data['format'] ?? null) !== 'jj-project-data' || ($data['schema_version'] ?? null) !== Schema::VERSION
            || array_keys($data['tables'] ?? []) !== Schema::TABLES) {
            throw new RuntimeException('Unsupported export format or schema version. Use the matching application version.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->exec('DELETE FROM user_mfa');
            foreach (array_reverse(Schema::TABLES) as $table) { $this->db->exec("DELETE FROM $table"); }
            foreach (Schema::TABLES as $table) {
                // Read column names from the installed schema, never trust SQL identifiers in an import.
                $query = $this->db->query("SELECT * FROM $table WHERE 1=0");
                $columns = [];
                for ($index = 0; $index < $query->columnCount(); $index++) {
                    $columns[] = $query->getColumnMeta($index)['name'];
                }
                $sql = "INSERT INTO $table (" . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $insert = $this->db->prepare($sql);
                if (!is_array($data['tables'][$table])) { throw new RuntimeException('Invalid table data.'); }
                foreach ($data['tables'][$table] as $row) {
                    if (!is_array($row) || count($row) !== count($columns) || array_diff($columns, array_keys($row))) {
                        throw new RuntimeException("Invalid columns for $table.");
                    }
                    if ($table === 'users') { $row['session_version'] = random_int(100000, 2000000000); }
                    $insert->execute(array_map(fn($column) => $row[$column], $columns));
                }
            }
            $this->db->exec('DELETE FROM login_attempts');
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}
