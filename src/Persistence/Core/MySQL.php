<?php
declare(strict_types=1);

namespace App\Persistence\Core;

use App\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Pdo as Base;
use PDO;

class MySQL extends Base implements PersistenceInterface
{
    protected ?array $channelsCache = null;

    public function prepare(): void
    {
        $this->withDatabaseConnection(fn (PDO $pdo) =>
            $this->checkSchema($pdo, 'core_schema_version'));
    }

    protected function getSchema(): array
    {
        return [
            1 => [
                <<<SQL
                CREATE TABLE IF NOT EXISTS `core_channels` (
                  `id`         INT(11)      NOT NULL AUTO_INCREMENT,
                  `channel`    VARCHAR(255) UNIQUE NOT NULL,
                  `created_at` INT          NOT NULL,
                  `updated_at` INT          NOT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                SQL,
                <<<SQL
                CREATE TABLE IF NOT EXISTS `behold_channels` (
                  `channel`    VARCHAR(255) UNIQUE NOT NULL,
                  `created_at` INT          NOT NULL,
                  `updated_at` INT          NOT NULL,
                  PRIMARY KEY (`channel`)
                ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                SQL,
            ],
        ];
    }

    private function normalize(string $ch): string
    {
        $ch = ltrim($ch, '#');
        return '#'.strtolower($ch);
    }

    public function getBeholdChannels(): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) {
            $stmt = $pdo->query('SELECT channel FROM behold_channels');
            if ($stmt === false) throw new PdoPersistenceException($pdo);
            return array_map([$this,'normalize'], $stmt->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    public function addBeholdChannel(string $ch): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) use ($ch) {
            $ch = $this->normalize($ch);
            $pdo->prepare(
                'INSERT IGNORE INTO behold_channels(channel,created_at,updated_at)
                 VALUES(:c,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())'
            )->execute($this->utf8Params(['c'=>$ch]));
            return $this->getBeholdChannels();
        });
    }

    public function removeBeholdChannel(string $ch): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) use ($ch) {
            $pdo->prepare('DELETE FROM behold_channels WHERE channel=:c')
                ->execute($this->utf8Params(['c'=>$this->normalize($ch)]));
            return $this->getBeholdChannels();
        });
    }

    public function getChannels(): array
    {
        return $this->channelsCache ??= $this->fetchChannelsFromDb();
    }

    public function refreshChannels(): array
    {
        return $this->channelsCache = $this->fetchChannelsFromDb();
    }

    public function addChannel(string $ch): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) use ($ch) {
            $pdo->prepare(
                'INSERT INTO core_channels SET channel=:c,created_at=:t,updated_at=:t'
            )->execute($this->utf8Params(['c'=>$this->normalize($ch),'t'=>time()]));
            return $this->refreshChannels();
        });
    }

    public function removeChannel(string $ch): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) use ($ch) {
            $pdo->prepare('DELETE FROM core_channels WHERE channel=:c')
                ->execute($this->utf8Params(['c'=>$this->normalize($ch)]));
            return $this->refreshChannels();
        });
    }

    private function fetchChannelsFromDb(): array
    {
        return $this->withDatabaseConnection(function (PDO $pdo) {
            $rows = [];
            $stmt = $pdo->query('SELECT id,channel FROM core_channels');
            if ($stmt === false) throw new PdoPersistenceException($pdo);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[(int)$r['id']] = $this->normalize($r['channel']);
            }
            return $rows;
        });
    }
}
