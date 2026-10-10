<?php
namespace MuseDockPanel\ChatGpt;

/** Local-only state: never replicated with panel_settings; no raw codes/tokens. */
final class OAuthStore
{
    private \PDO $db;
    public function __construct(?string $path = null)
    {
        $path ??= PANEL_ROOT . '/storage/chatgpt/oauth.sqlite';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se puede crear el almacenamiento OAuth.');
        }
        $old = umask(0077);
        try {
            $this->db = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            $this->db->exec('PRAGMA busy_timeout=5000');
            $this->db->exec('CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, window INTEGER NOT NULL, count INTEGER NOT NULL)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS config (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS grants (hash TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL, expires INTEGER NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
        } finally { umask($old); }
    }
    public function rateLimit(string $identity, string $action, int $limit): bool
    {
        $window = intdiv(time(), 60);
        $key = hash('sha256', $identity . ':' . $action);
        $q = $this->db->prepare('INSERT INTO rate_limits VALUES (?,?,1) ON CONFLICT(key) DO UPDATE SET window=excluded.window, count=CASE WHEN rate_limits.window=excluded.window THEN rate_limits.count+1 ELSE 1 END RETURNING count');
        $q->execute([$key, $window]); $count = (int)$q->fetchColumn(); $q->closeCursor();
        $this->db->exec('DELETE FROM rate_limits WHERE window < ' . ($window - 10));
        return $count <= $limit;
    }
    public function get(string $key, string $default = ''): string
    {
        $q = $this->db->prepare('SELECT value FROM config WHERE key=?'); $q->execute([$key]);
        $v = $q->fetchColumn(); return $v === false ? $default : (string)$v;
    }
    public function set(string $key, string $value): void
    {
        $q = $this->db->prepare('INSERT INTO config VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value'); $q->execute([$key, $value]);
    }
    public function issue(string $kind, array $payload, int $ttl): string
    {
        $value = bin2hex(random_bytes(32));
        $q = $this->db->prepare('INSERT INTO grants(hash,kind,payload,expires) VALUES (?,?,?,?)');
        $q->execute([hash('sha256', $value), $kind, json_encode($payload, JSON_THROW_ON_ERROR), time() + $ttl]);
        $this->db->exec('DELETE FROM grants WHERE expires < ' . (time() - 86400));
        return $value;
    }
    public function find(string $value, string $kind): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $value)) return null;
        $q = $this->db->prepare('SELECT payload FROM grants WHERE hash=? AND kind=? AND expires>? AND used=0');
        $q->execute([hash('sha256', $value), $kind, time()]); $v = $q->fetchColumn();
        return $v === false ? null : json_decode($v, true, 32, JSON_THROW_ON_ERROR);
    }
    public function consume(string $value, string $kind): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $payload = $this->find($value, $kind);
            if ($payload !== null) {
                $q = $this->db->prepare('UPDATE grants SET used=1 WHERE hash=? AND kind=?'); $q->execute([hash('sha256', $value), $kind]);
            }
            $this->db->exec('COMMIT'); return $payload;
        } catch (\Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function revokeFamily(string $family): void
    {
        $q = $this->db->prepare("DELETE FROM grants WHERE json_extract(payload, '$.family')=?"); $q->execute([$family]);
    }
    public function usedRefresh(string $value): ?array
    {
        $q = $this->db->prepare("SELECT payload FROM grants WHERE hash=? AND kind='refresh' AND used=1 AND expires>?");
        $q->execute([hash('sha256', $value), time()]); $v = $q->fetchColumn();
        return $v === false ? null : json_decode($v, true, 32, JSON_THROW_ON_ERROR);
    }
    /** Private approval queue: IDs are hashes, never access/refresh token values. */
    public function pendingOperations(): array
    {
        $q=$this->db->query("SELECT hash,payload FROM grants WHERE kind='write_operation' AND expires>".time()." AND used=0 ORDER BY expires LIMIT 20");
        return array_map(static fn($r)=>['id'=>$r['hash'],'payload'=>json_decode($r['payload'],true,32,JSON_THROW_ON_ERROR)],$q->fetchAll());
    }
    public function consumeOperation(string $hash): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) return null;
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $q=$this->db->prepare("SELECT payload FROM grants WHERE hash=? AND kind='write_operation' AND expires>? AND used=0");$q->execute([$hash,time()]);$value=$q->fetchColumn();$q->closeCursor();
            if ($value!==false) {$q=$this->db->prepare("UPDATE grants SET used=1 WHERE hash=? AND kind='write_operation'");$q->execute([$hash]);}
            $this->db->exec('COMMIT');return $value===false?null:json_decode($value,true,32,JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {$this->db->exec('ROLLBACK');throw $e;}
    }
    public function activeFamily(string $family, int $userId, string $scope): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM grants WHERE kind='access' AND expires>? AND used=0 AND json_extract(payload,'$.family')=? AND json_extract(payload,'$.user_id')=CAST(? AS INTEGER) AND json_extract(payload,'$.scope')=? LIMIT 1");
        $q->execute([time(),$family,$userId,$scope]);return $q->fetchColumn()!==false;
    }
    public function revokeAll(): void { $this->db->exec('DELETE FROM grants'); }
}
