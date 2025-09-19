<?php
declare(strict_types=1);

namespace App\Modules\Quotes\Persistence;

use App\Modules\Quotes\Persistence\Exceptions\PdoPersistenceException;
use App\Persistence\Pdo;

/**
 * Stores and fetches quotes.
 */
class MySQL extends Pdo implements PersistenceInterface
{
    /* unchanged constructor ­/ prepare / getSchema / getQuote ---------------- */

    public function prepare(): void
    {
        $this->withDatabaseConnection(function (\PDO $c) {
            $this->checkSchema($c, 'quotes_schema_version');
        });
    }

    protected function getSchema(): array
    {
        return [
            1 => [
                <<<SQL
                CREATE TABLE IF NOT EXISTS `quotes` (
                  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `content` VARCHAR(400) NOT NULL,
                  PRIMARY KEY(`id`)
                ) ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_bin;
                SQL,
            ],
        ];
    }

    public function getQuote($searchTerm = null): ?string
    {
        return $this->withDatabaseConnection(function (\PDO $c) use ($searchTerm) {
            $sql = 'SELECT content FROM quotes'
                 . (is_null($searchTerm) ? '' : ' WHERE content LIKE :term')
                 . ' ORDER BY RAND() LIMIT 1';
            $st  = $c->prepare($sql);

            if ($searchTerm !== null) {
                $st->bindValue('term', '%'.$searchTerm.'%');
            }
            if (!$st->execute()) {
                throw new PdoPersistenceException($c);
            }
            return $st->rowCount() ? $st->fetch(\PDO::FETCH_COLUMN) : null;
        });
    }

    /* ───── NEW helper: force valid UTF-8 ───── */
    private function sanitize(string $str): string
    {
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;                             // already fine
        }
        // attempt CP-1252 → UTF-8 fallback
        return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    }

    /* ───── NEW method used by the bot’s writer ───── */
    public function addQuote(string $raw): void
    {
        $quote = $this->sanitize($raw);              // <- fix

        $this->withDatabaseConnection(function (\PDO $c) use ($quote) {
            $st = $c->prepare('INSERT INTO quotes (content) VALUES (:q)');
            if (!$st->execute(['q' => $quote])) {
                throw new PdoPersistenceException($c);
            }
        });
    }
}
