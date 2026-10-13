<?php
namespace App\Api;
use PDO;

final class Storage {
    public readonly PDO $pdo;
    public function __construct(public readonly Config $config) {
        $path = $config->dataDir . '/api.sqlite';
        $this->pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, body TEXT NOT NULL, updated_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1);
            CREATE TABLE IF NOT EXISTS sessions (hash TEXT PRIMARY KEY, admin_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE, expires_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS session_expiry ON sessions(expires_at);
            CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, reset_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS schools (key TEXT PRIMARY KEY, name TEXT, metadata TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS lessons (cache_key TEXT NOT NULL, school_key TEXT NOT NULL, week INTEGER NOT NULL, class_id INTEGER NOT NULL, class_label TEXT NOT NULL,
                date TEXT NOT NULL, period INTEGER NOT NULL, start TEXT NOT NULL, end TEXT NOT NULL, teacher TEXT, room TEXT, body TEXT NOT NULL);
            CREATE INDEX IF NOT EXISTS teacher_lookup ON lessons(school_key, week, teacher, date);
            CREATE INDEX IF NOT EXISTS room_lookup ON lessons(school_key, week, room, date);
            CREATE INDEX IF NOT EXISTS cache_lessons ON lessons(cache_key);
            CREATE TABLE IF NOT EXISTS jobs (id TEXT PRIMARY KEY, school_key TEXT NOT NULL, scope TEXT NOT NULL, week INTEGER,
                status TEXT NOT NULL, total INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, failed INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL, owner INTEGER NOT NULL, error TEXT);
            CREATE TABLE IF NOT EXISTS tasks (job_id TEXT NOT NULL REFERENCES jobs(id) ON DELETE CASCADE, class_id INTEGER NOT NULL, week INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending', PRIMARY KEY(job_id,class_id,week));
            CREATE INDEX IF NOT EXISTS job_status ON jobs(status,created_at);");
        @chmod($path, 0600);
    }
    public function query(string $sql, array $args = []): \PDOStatement { $s = $this->pdo->prepare($sql); $s->execute($args); return $s; }
    public function atomic(callable $operation): mixed {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $operation(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
    public function cache(string $key): ?array {
        $row = $this->query('SELECT * FROM cache WHERE key=?', [$key])->fetch();
        return $row ? ['data' => json_decode($row['body'], true, 512, JSON_THROW_ON_ERROR), 'updated_at' => (int)$row['updated_at']] : null;
    }
    public function putCache(string $key, array $data): void {
        $this->query('INSERT INTO cache VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET body=excluded.body,updated_at=excluded.updated_at', [$key,json_encode($data, JSON_THROW_ON_ERROR),time()]);
    }
    public function saveMeta(array $meta): void {
        $this->atomic(function () use ($meta) {
            $this->putCache('school:' . $meta['schoolKey'], $meta);
            $this->query('INSERT INTO schools VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET name=excluded.name,metadata=excluded.metadata',
                [$meta['schoolKey'],$meta['schoolName'],json_encode($meta,JSON_THROW_ON_ERROR)]);
        });
    }
    public function saveWeek(string $key, string $school, int $week, string $label, array $data): void {
        $this->atomic(function () use ($key,$school,$week,$label,$data) {
            $this->putCache($key,$data);
            $this->query('DELETE FROM lessons WHERE cache_key=?',[$key]);
            $insert = $this->pdo->prepare('INSERT INTO lessons VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($data['days'] as $day) foreach ($day['lessonsByPeriod'] as $period) {
                if (!$period) continue;
                foreach ($period['lessons'] as $lesson) {
                    $lesson['sourceClassLabel']=$label; $lesson['sourceClassId']=(string)$data['classId'];
                    $teacher = trim($lesson['teacherFullName'] ?: ($lesson['teacher'] ?? ''));
                    $insert->execute([$key,$school,$week,$data['classId'],$label,$day['date'],$period['periodNumber'],
                        $period['timeRange']['start'],$period['timeRange']['end'],$teacher ?: null,$lesson['room'],json_encode($lesson,JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }
    public function consume(string $key, int $maximum, int $window): bool {
        return $this->atomic(function () use ($key,$maximum,$window) {
            $now=time();
            $this->query('DELETE FROM limits WHERE reset_at < ?',[$now]);
            $row=$this->query('SELECT * FROM limits WHERE key=?',[$key])->fetch();
            if ($row && (int)$row['count'] >= $maximum) return false;
            $this->query('INSERT INTO limits VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,$now+$window]);
            return true;
        });
    }
    public function job(string $id): ?array { return $this->query('SELECT * FROM jobs WHERE id=?',[$id])->fetch() ?: null; }
    public function enqueue(string $school, string $scope, ?int $week, int $owner): array {
        return $this->atomic(function () use ($school,$scope,$week,$owner) {
            $existing=$this->query("SELECT * FROM jobs WHERE school_key=? AND scope=? AND week IS ? AND status IN ('queued','running') LIMIT 1",[$school,$scope,$week])->fetch();
            if ($existing) return $existing;
            $id=bin2hex(random_bytes(16)); $now=time();
            $this->query("INSERT INTO jobs(id,school_key,scope,week,status,created_at,updated_at,owner) VALUES(?,?,?,?,'queued',?,?,?)",[$id,$school,$scope,$week,$now,$now,$owner]);
            return $this->job($id);
        });
    }
    public function stats(): array {
        $result=[];
        foreach (['schools','cache','lessons','jobs'] as $table) $result[$table]=(int)$this->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        $result['active_jobs']=(int)$this->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')")->fetchColumn();
        return $result;
    }
}
