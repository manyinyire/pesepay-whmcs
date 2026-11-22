<?php
declare(strict_types=1);
namespace Azaire\PesePay;

class Store
{
    public const TABLE = 'mod_pesepaydirect_attempts';
    private \PDO $pdo;
    public function __construct(\PDO $pdo) { $this->pdo = $pdo; }
    public function install(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL UNIQUE, request_key VARCHAR(64) NOT NULL UNIQUE,
            invoice_id INT NOT NULL, client_id INT NOT NULL,
            amount_cents BIGINT NOT NULL, invoice_currency VARCHAR(8) NOT NULL, api_currency VARCHAR(8) NOT NULL,
            method VARCHAR(16) NOT NULL, environment VARCHAR(12) NOT NULL,
            reference VARCHAR(128) NULL UNIQUE, candidate_reference VARCHAR(128) NULL,
            callback_url VARCHAR(1024) NOT NULL, status VARCHAR(40) NOT NULL,
            metadata TEXT NOT NULL, credited SMALLINT NOT NULL DEFAULT 0,
            otp_submitted SMALLINT NOT NULL DEFAULT 0, needs_check SMALLINT NOT NULL DEFAULT 1,
            created_at BIGINT NOT NULL, checked_at BIGINT NOT NULL DEFAULT 0,
            INDEX invoice_lookup (invoice_id, id), INDEX reconciliation (credited, checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }
    public function lock(int $invoice, callable $fn)
    {
        $name = 'pesepaydirect:' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 12) . ':' . $invoice;
        $q = $this->pdo->prepare('SELECT GET_LOCK(?, 3)'); $q->execute([$name]);
        if ((int) $q->fetchColumn() !== 1) { throw new \RuntimeException('Payment is being checked. Please wait a moment.'); }
        try { return $fn(); } finally { $q = $this->pdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$name]); }
    }
    public function insert(array $data): array
    {
        $cols = array_keys($data); $this->columns($cols);
        $q = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $q->execute(array_values($data)); return $this->get((int) $this->pdo->lastInsertId());
    }
    public function update(int $id, array $data): void
    {
        $cols = array_keys($data); $this->columns($cols);
        $q = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET ' . implode(',', array_map(static fn($c) => $c . '=?', $cols)) . ' WHERE id=?');
        $q->execute(array_merge(array_values($data), [$id]));
    }
    private function columns(array $cols): void
    {
        $allowed = ['token','request_key','invoice_id','client_id','amount_cents','invoice_currency','api_currency','method','environment','reference','candidate_reference','callback_url','status','metadata','credited','otp_submitted','needs_check','created_at','checked_at'];
        if (array_diff($cols, $allowed)) { throw new \LogicException('Unknown database column.'); }
    }
    public function get(int $id): ?array { return $this->one('id=?', [$id]); }
    public function token(string $token): ?array { return $this->one('token=?', [$token]); }
    public function request(string $key): ?array { return $this->one('request_key=?', [$key]); }
    public function latest(int $invoice, ?string $environment=null): ?array { return $environment === null ? $this->one('invoice_id=? ORDER BY id DESC', [$invoice]) : $this->one('invoice_id=? AND environment=? ORDER BY id DESC', [$invoice,$environment]); }
    private function one(string $where, array $params): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . self::TABLE . ' WHERE ' . $where . ' LIMIT 1'); $q->execute($params);
        return $q->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
    public function queue(int $id, string $reference): void
    {
        // Do not overwrite a different reference or an earlier callback's candidate.
        $q = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET candidate_reference=?, needs_check=1 WHERE id=? AND (reference IS NULL OR reference=?) AND (candidate_reference IS NULL OR candidate_reference=?)');
        $q->execute([$reference, $id, $reference, $reference]);
    }
    public function due(string $environment = 'production'): array
    {
        $q = $this->pdo->prepare("SELECT * FROM " . self::TABLE . " WHERE credited=0 AND environment=? AND (reference IS NOT NULL OR candidate_reference IS NOT NULL) AND (needs_check=1 OR (checked_at<? AND status NOT IN ('TERMINATED','CLOSED','CLOSED_PERIOD_ELAPSED','INSUFFICIENT_FUNDS','CANCELLED','DECLINED','AUTHORIZATION_FAILED','REVERSED'))) ORDER BY checked_at ASC, id ASC LIMIT 20");
        $q->execute([$environment, time() - 300]); return $q->fetchAll(\PDO::FETCH_ASSOC);
    }
}
