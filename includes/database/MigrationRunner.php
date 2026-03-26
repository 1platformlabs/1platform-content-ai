<?php

if (!defined('ABSPATH')) exit;

class ContaiMigrationRunner {

    private const OPTION_DB_VERSION = 'contai_db_version';
    private const OPTION_MIGRATION_ERROR = 'contai_migration_error';

    /** @var array<int, object> Version => migration instance */
    private array $migrations = [];

    public function register(int $version, object $migration): void {
        $this->migrations[$version] = $migration;
    }

    public function run(): array {
        $current_version = $this->getCurrentVersion();
        $pending = $this->getPendingMigrations($current_version);

        if (empty($pending)) {
            return [
                'success' => true,
                'message' => 'No pending migrations',
                'version' => $current_version,
                'applied' => [],
            ];
        }

        $applied = [];

        foreach ($pending as $version => $migration) {
            $class_name = get_class($migration);

            try {
                $result = $migration->up();
            } catch (\Exception $e) {
                $result = false;
                contai_log(sprintf('%s migration exception: %s', $class_name, $e->getMessage()));
            }

            if ($result === false) {
                contai_log(sprintf('%s migration failed at version %d', $class_name, $version));

                $this->rollback($applied);
                $error_message = sprintf(
                    'Migration %s (v%d) failed. All changes from this batch have been rolled back.',
                    $class_name,
                    $version
                );
                $this->storeError($error_message);

                return [
                    'success' => false,
                    'message' => $error_message,
                    'version' => $current_version,
                    'applied' => [],
                    'failed_at' => $version,
                ];
            }

            $applied[$version] = $migration;
            $this->setCurrentVersion($version);

            contai_log(sprintf('%s migration applied (v%d)', $class_name, $version));
        }

        $this->clearError();

        return [
            'success' => true,
            'message' => sprintf('%d migration(s) applied successfully', count($applied)),
            'version' => $this->getCurrentVersion(),
            'applied' => array_keys($applied),
        ];
    }

    private function rollback(array $applied): void {
        $reversed = array_reverse($applied, true);

        foreach ($reversed as $version => $migration) {
            $class_name = get_class($migration);

            if (!method_exists($migration, 'down')) {
                contai_log(sprintf('%s has no down() method, skipping rollback for v%d', $class_name, $version));
                continue;
            }

            try {
                $migration->down();
                contai_log(sprintf('%s rolled back (v%d)', $class_name, $version));
            } catch (\Exception $e) {
                contai_log(sprintf('%s rollback failed (v%d): %s', $class_name, $version, $e->getMessage()));
            }
        }
    }

    public function getCurrentVersion(): int {
        return (int) get_option(self::OPTION_DB_VERSION, 0);
    }

    private function setCurrentVersion(int $version): void {
        update_option(self::OPTION_DB_VERSION, $version, false);
    }

    private function getPendingMigrations(int $current_version): array {
        ksort($this->migrations);

        return array_filter($this->migrations, function (int $version) use ($current_version) {
            return $version > $current_version;
        }, ARRAY_FILTER_USE_KEY);
    }

    private function storeError(string $message): void {
        update_option(self::OPTION_MIGRATION_ERROR, $message, false);
    }

    private function clearError(): void {
        delete_option(self::OPTION_MIGRATION_ERROR);
    }

    public static function getError(): ?string {
        $error = get_option(self::OPTION_MIGRATION_ERROR, '');
        return !empty($error) ? $error : null;
    }

    public static function hasError(): bool {
        return self::getError() !== null;
    }

    public static function clearStoredError(): void {
        delete_option(self::OPTION_MIGRATION_ERROR);
    }

    public function getMigrations(): array {
        ksort($this->migrations);
        return $this->migrations;
    }
}
