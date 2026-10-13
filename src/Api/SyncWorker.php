<?php
namespace App\Api;

final class SyncWorker {
    public function __construct(private Storage $db, private Timetables $timetables) {}
    public function once(): bool {
        $lock=fopen($this->db->config->dataDir.'/worker.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return false; }
        try {
            $job=$this->db->query("SELECT * FROM jobs WHERE status IN ('running','queued') ORDER BY CASE status WHEN 'running' THEN 0 ELSE 1 END,created_at LIMIT 1")->fetch();
            if (!$job) return false;
            $id=$job['id'];
            $this->db->query("UPDATE jobs SET status='running',updated_at=? WHERE id=?",[time(),$id]);
            try {
                $meta=$this->timetables->meta($job['school_key']);
                if (!$this->db->query('SELECT COUNT(*) FROM tasks WHERE job_id=?',[$id])->fetchColumn()) {
                    $weeks=$job['scope']==='year' ? $meta['allWeekIds'] : [$this->timetables->weekId($meta,$job['week']===null ? null : (int)$job['week'])];
                    $this->db->atomic(function () use ($id,$meta,$weeks) {
                        foreach ($weeks as $week) foreach ($meta['classes'] as $class)
                            $this->db->query('INSERT OR IGNORE INTO tasks(job_id,class_id,week) VALUES(?,?,?)',[$id,$class['id'],$week]);
                        $this->db->query('UPDATE jobs SET total=(SELECT COUNT(*) FROM tasks WHERE job_id=?) WHERE id=?',[$id,$id]);
                    });
                }
                $tasks=$this->db->query("SELECT * FROM tasks WHERE job_id=? AND status='pending' ORDER BY week,class_id",[$id])->fetchAll();
                foreach ($tasks as $task) {
                    $status='done';
                    try { $this->timetables->classWeek($meta,(int)$task['class_id'],(int)$task['week'],true); }
                    catch (\Throwable $e) { $status='failed'; error_log('Sync task failed for job '.$id.': '.get_class($e)); }
                    $this->db->atomic(function () use ($id,$task,$status) {
                        $this->db->query('UPDATE tasks SET status=? WHERE job_id=? AND class_id=? AND week=?',[$status,$id,$task['class_id'],$task['week']]);
                        $this->db->query("UPDATE jobs SET completed=(SELECT COUNT(*) FROM tasks WHERE job_id=? AND status='done'),
                            failed=(SELECT COUNT(*) FROM tasks WHERE job_id=? AND status='failed'),updated_at=? WHERE id=?",[$id,$id,time(),$id]);
                    });
                }
                $final=$this->db->job($id);
                $this->db->query('UPDATE jobs SET status=?,updated_at=? WHERE id=?',[$final['failed']>0 ? 'partial' : 'succeeded',time(),$id]);
            } catch (\Throwable $e) {
                error_log('Sync job failed: '.get_class($e));
                $this->db->query("UPDATE jobs SET status='failed',error='Synchronization failed. Check worker logs.',updated_at=? WHERE id=?",[time(),$id]);
            }
            return true;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
