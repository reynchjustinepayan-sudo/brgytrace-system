<?php

function local_backup_directory(): string {
    return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'BrgyTraceBackups';
}

function local_backup_is_due(PDO $pdo, DateTimeImmutable $now): bool {
    if (app_setting($pdo, 'backup_enabled', '0') !== '1') {
        return false;
    }

    $frequency = app_setting($pdo, 'backup_frequency', 'daily');
    $time = app_setting($pdo, 'backup_time', '02:00');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
        $time = '02:00';
    }

    $scheduledDate = new DateTimeImmutable($now->format('Y-m-d'));
    if ($frequency === 'weekly') {
        $backupDay = max(1, min(7, (int)app_setting($pdo, 'backup_day', '1')));
        while ((int)$scheduledDate->format('N') !== $backupDay) {
            $scheduledDate = $scheduledDate->modify('-1 day');
        }
    } elseif ($frequency === 'monthly') {
        $scheduledDate = new DateTimeImmutable($now->format('Y-m-01'));
    }
    $scheduledAt = new DateTimeImmutable($scheduledDate->format('Y-m-d') . ' ' . $time);
    if ($now < $scheduledAt) {
        return false;
    }

    $scheduleUpdated = app_setting($pdo, 'backup_schedule_updated_at', '1970-01-01 00:00:00');
    $scheduleUpdatedTimestamp = strtotime($scheduleUpdated);
    if ($scheduleUpdatedTimestamp !== false && $scheduledAt->getTimestamp() < $scheduleUpdatedTimestamp) {
        return false;
    }

    $lastRun = app_setting($pdo, 'backup_last_run', '');
    if ($lastRun === '' || strtotime($lastRun) === false) {
        return true;
    }
    return new DateTimeImmutable($lastRun) < $scheduledAt;
}

function create_local_backup(PDO $pdo): string {
    $directory = local_backup_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the local backup folder.');
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    if (!$tables) {
        throw new RuntimeException('No database tables were found to back up.');
    }
    $database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($database === '') {
        throw new RuntimeException('Could not determine which database to back up.');
    }

    $filename = 'brgytrace_legacy_' . date('Ymd_His') . '.sql';
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $path . '.tmp';
    $handle = fopen($temporaryPath, 'xb');
    if ($handle === false) {
        throw new RuntimeException('Could not create a local backup file.');
    }

    $complete = false;
    try {
        $write = static function (string $contents) use ($handle): void {
            $length = strlen($contents);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not write the local backup file.');
                }
                $offset += $written;
            }
        };

        $write("-- BrgyTrace local database backup\n");
        $quotedDatabase = '`' . str_replace('`', '``', $database) . '`';
        $write("SET NAMES utf8mb4;\nUSE {$quotedDatabase};\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
            if (!$create || !isset($create[1])) {
                throw new RuntimeException('Could not read the schema for a database table.');
            }
            $write('DROP TABLE IF EXISTS ' . $quotedTable . ";\n" . $create[1] . ";\n\n");
        }

        foreach ($tables as $table) {
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $columns = $pdo->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_COLUMN);
            $quotedColumns = array_map(static function ($column): string {
                return '`' . str_replace('`', '``', $column) . '`';
            }, $columns);
            $statement = $pdo->query('SELECT * FROM ' . $quotedTable);
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $quotedValue = $pdo->quote((string)$value);
                        if ($quotedValue === false) {
                            throw new RuntimeException('Could not encode a database value for the backup.');
                        }
                        $values[] = $quotedValue;
                    }
                }
                $write('INSERT INTO ' . $quotedTable . ' (' . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fclose($handle)) {
            $handle = null;
            throw new RuntimeException('Could not finish writing the local backup file.');
        }
        $handle = null;
        if (!rename($temporaryPath, $path)) {
            throw new RuntimeException('Could not finalize the local backup file.');
        }
        $complete = true;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (!$complete && is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    return $path;
}

function run_due_local_backup(PDO $pdo): ?string {
    $now = new DateTimeImmutable();
    if (!local_backup_is_due($pdo, $now)) {
        return null;
    }

    $directory = local_backup_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the local backup folder.');
    }
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.backup.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not lock the local backup folder.');
    }

    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return null;
        }
        $now = new DateTimeImmutable();
        if (!local_backup_is_due($pdo, $now)) {
            return null;
        }

        $path = create_local_backup($pdo);
        $pdo->beginTransaction();
        try {
            save_app_setting($pdo, 'backup_last_run', $now->format('Y-m-d H:i:s'));
            audit($pdo, 'Scheduled local backup created', basename($path));
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $ex;
        }
        return $path;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
