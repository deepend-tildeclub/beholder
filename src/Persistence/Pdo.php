<?php

namespace App\Persistence;

use App\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Exceptions\PersistenceException;

abstract class Pdo
{
    protected string $hostname;
    protected string $database;
    protected string $username;
    protected string $password;

    public function __construct(array $options)
    {
        $this->hostname = $options['hostname'];
        $this->username = $options['username'];
        $this->password = $options['password'];
        $this->database = $options['database'];
    }

    protected function withDatabaseConnection(callable $fn)
    {
        $connectionResource = $this->connect();

        $result = $fn($connectionResource);

        $connectionResource = null;

        return $result;
    }

    protected function withTransaction(\PDO $connectionResource, callable $fn)
    {
        $connectionResource->beginTransaction();

        try {
            $result = $fn($connectionResource);
            $connectionResource->commit();
        } catch (\Exception $exception) {
            $connectionResource->rollback();
            throw $exception;
        }

        return $result;
    }

    /**
     * @return \PDO
     * @throws \Exception
     */
    protected function connect() : \PDO
    {
        $attempt = 1;
        $maxAttempts = 12;
        $connectionResource = null;
        do {
            if ($attempt > 1) {
                echo "Connecting to database (attempt $attempt of $maxAttempts)\n\r";
            }

            try {
                $connectionResource = new \PDO(
                    'mysql:dbname=' . $this->database . ';host=' . $this->hostname . ';charset=utf8mb4',
                    $this->username,
                    $this->password,
                    [
                        \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                    ]
                );
            } catch (\PDOException $exception) {
                sleep(5);
            }
        } while (is_null($connectionResource) && $attempt++ && $attempt < $maxAttempts);

        if (is_null($connectionResource)) {
            throw new \Exception(
                'Could not connect to database',
                0,
                $exception ?? null,
            );
        }

        return $connectionResource;
    }

    protected function checkSchema(\PDO $connectionResource, $schemaConfigKey)
    {
        $result = $connectionResource->query('SHOW TABLES LIKE "core_config"');

        if (false === $result) {
            throw new PdoPersistenceException($connectionResource);
        }

        $isTableMissing = $result->rowCount() === 0;

        $result->closeCursor();

        if ($isTableMissing) {
            $result = $connectionResource->query(
                <<< EOD
                CREATE TABLE `core_config` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `config_key` VARCHAR(255) NOT NULL DEFAULT '',
                    `config_value` VARCHAR(255) NOT NULL DEFAULT '',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY(`config_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                EOD
            );

            if (false === $result) {
                throw new PdoPersistenceException($connectionResource);
            }
        }

        $statement = $connectionResource->prepare(
            <<< EOD
            SELECT `config_value`
            FROM `core_config`
            WHERE `config_key` = :key
            LIMIT 1
            EOD
        );

        $statement->execute([
            'key' => $schemaConfigKey,
        ]);

        if (false === $statement) {
            throw new PdoPersistenceException($connectionResource);
        }

        if ($statement->rowCount() === 0) {
            // No entry, so we can assume the schema isn't set up.
            $this->migrateSchema($connectionResource, $schemaConfigKey);
            return;
        }

        $result = $statement->fetch(\PDO::FETCH_ASSOC);

        $expectedSchemaVersion = $this->getLatestSchemaVersion();

        if ($result['config_value'] == $expectedSchemaVersion) {
            // Schema version matches.
            return;
        }

        $actualSchemaVersion = $result['config_value'];

        if ($actualSchemaVersion < $expectedSchemaVersion) {
            $this->migrateSchema($connectionResource, $schemaConfigKey, $actualSchemaVersion);
        }

        throw new PersistenceException(
            "Unexpected schema version ($actualSchemaVersion in use, $expectedSchemaVersion expected)"
        );
    }

    abstract protected function getSchema() : array;

    protected function getLatestSchemaVersion(): int
    {
        return max(array_keys($this->getSchema()));
    }

    protected function migrateSchema(\PDO $connectionResource, string $subSchemaName, ?int $afterSchemaVersion = null)
    {
        $currentSchemaVersion = null;
        foreach ($this->getSchema() as $schemaVersion => $schemaCommands) {
            if (! is_null($afterSchemaVersion) && $schemaVersion <= $afterSchemaVersion) {
                // Migration has already been applied
                continue;
            }

            foreach ($schemaCommands as $schemaCommand) {
                if (! $connectionResource->query($schemaCommand)) {
                    throw new PdoPersistenceException($connectionResource);
                }
            }

            $currentSchemaVersion = $schemaVersion;
        }

        $statement = $connectionResource->prepare(
            <<< EOD
            INSERT INTO `core_config`
            SET `config_key` = :key,
            `config_value` = :value
            ON DUPLICATE KEY UPDATE `config_value` = :value;
            EOD
        );

        $params = [
            'key' => $subSchemaName,
            'value' => $currentSchemaVersion,
        ];

        if (! $statement->execute($params)) {
            throw new PdoPersistenceException($connectionResource);
        }
    }

    /**
     * Normalize potentially non-UTF-8 input (e.g., CP-1252 smart quotes) to UTF-8.
     * Use this on any user/IRC text before binding to SQL.
     */
    protected function utf8(string $s): string
    {
        // Fast UTF-8 validity check; valid strings pass through unchanged.
        if (preg_match('//u', $s) === 1) {
            return $s;
        }
        if (\function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($s, 'UTF-8', 'Windows-1252, ISO-8859-1');
        }
        if (\function_exists('iconv')) {
            $r = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $s);
            return $r !== false ? $r : $s;
        }
        return $s;
    }

    /**
     * Convenience: normalize only string values in a params array.
     */
    protected function utf8Params(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_string($v)) {
                $params[$k] = $this->utf8($v);
            }
        }
        return $params;
    }

}
