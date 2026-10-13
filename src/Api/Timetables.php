<?php
namespace App\Api;
use App\Client\EAsistentClient;

final class Timetables {
    public function __construct(private Storage $db, private EAsistentClient $client) {}
    public static function validateKey(string $key): void {
        if (!preg_match('/^[A-Za-z0-9_-]{8,128}$/',$key)) throw new HttpError(422,'invalid_school','Invalid school key.');
    }
    public function meta(string $key, bool $force = false): array {
        self::validateKey($key);
        $cache=$this->db->cache('school:'.$key);
        if (!$force && $cache && time()-$cache['updated_at']<600) return $cache['data'];
        return $this->locked('school:'.$key,function () use ($key,$force) {
            $cache=$this->db->cache('school:'.$key);
            if (!$force && $cache && time()-$cache['updated_at']<600) return $cache['data'];
            $meta=json_decode(json_encode($this->client->getSchoolMeta($key),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            if (!$meta['classes']) throw new HttpError(502,'upstream_format','The school page returned no classes.');
            $this->db->saveMeta($meta); return $meta;
        });
    }
    public function weekId(array $meta, ?int $week): int {
        $week = $week ?: $meta['currentWeekId'];
        if (!$week || !in_array($week,$meta['allWeekIds'],true)) throw new HttpError(422,'invalid_week','Choose an available school week.');
        return $week;
    }
    public function cacheKey(array $meta, int $class, int $week): string {
        $generation=hash('sha256',json_encode(array_column($meta['classes'],'id')));
        return 'week:'.$meta['schoolKey'].':'.$generation.':'.$class.':'.$week;
    }
    public function classWeek(array $meta, int $classId, int $week, bool $force = false): array {
        $class=null; foreach ($meta['classes'] as $candidate) if ($candidate['id']===$classId) $class=$candidate;
        if (!$class) throw new HttpError(404,'class_not_found','Class not found in this school.');
        $key=$this->cacheKey($meta,$classId,$week);
        $cached=$this->db->cache($key);
        if (!$force && $cached && time()-$cached['updated_at']<$this->db->config->cacheTtl) return $cached['data'];
        return $this->locked($key,function () use ($key,$meta,$class,$classId,$week,$force) {
            $cached=$this->db->cache($key);
            if (!$force && $cached && time()-$cached['updated_at']<$this->db->config->cacheTtl) return $cached['data'];
            $data=json_decode(json_encode($this->client->getTimetable($meta['schoolKey'],$classId,$week),JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            $this->db->saveWeek($key,$meta['schoolKey'],$week,$class['label'],$data);
            return $data;
        });
    }
    private function locked(string $key, callable $operation): array {
        $handle=fopen($this->db->config->dataDir.'/'.hash('sha256',$key).'.lock','c');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) fclose($handle); throw new HttpError(503,'refresh_in_progress','This timetable is being refreshed. Try again shortly.'); }
        try { return $operation(); } finally { flock($handle,LOCK_UN); fclose($handle); }
    }
    public function coverage(array $meta, int $week): array {
        $missing=[];
        foreach ($meta['classes'] as $class) {
            $cache=$this->db->cache($this->cacheKey($meta,$class['id'],$week));
            if (!$cache || time()-$cache['updated_at'] >= $this->db->config->cacheTtl) $missing[]=$class['id'];
        }
        return ['complete'=>!$missing,'missing_class_ids'=>$missing,'classes_total'=>count($meta['classes'])];
    }
    public function cachedWeeks(array $meta, int $week): array {
        $result=[];
        foreach ($meta['classes'] as $class) {
            $cache=$this->db->cache($this->cacheKey($meta,$class['id'],$week));
            if ($cache) $result[]=$cache['data'];
        }
        return $result;
    }
    public function entities(array $meta, string $column): array {
        $this->validateColumn($column);
        $ids=array_column($meta['classes'],'id');
        if (!$ids) return [];
        $params=implode(',',array_fill(0,count($ids),'?'));
        $result=$this->db->query("SELECT DISTINCT $column FROM lessons WHERE school_key=? AND class_id IN ($params) AND $column IS NOT NULL",array_merge([$meta['schoolKey']],$ids))->fetchAll(\PDO::FETCH_COLUMN);
        natcasesort($result); return array_values($result);
    }
    private function validateColumn(string $column): void { if (!in_array($column,['teacher','room'],true)) throw new \LogicException('Invalid column'); }
    public function aggregate(array $meta, int $week, string $column, string $value): array {
        $this->validateColumn($column);
        $days=[]; $base=null;
        foreach ($meta['classes'] as $class) {
            $key=$this->cacheKey($meta,$class['id'],$week); $cache=$this->db->cache($key);
            if (!$cache) continue;
            $base ??= $cache['data'];
            foreach ($cache['data']['days'] as $day) $days[$day['date']] ??= [];
            foreach ($this->db->query("SELECT * FROM lessons WHERE cache_key=? AND $column=? ORDER BY date,start,period",[$key,$value])->fetchAll() as $row) {
                $slot=$row['start'].'-'.$row['end'].($row['start']===$row['end'] ? '-'.$row['period'] : '');
                $days[$row['date']][$slot] ??= ['periodNumber'=>(int)$row['period'],'timeRange'=>['start'=>$row['start'],'end'=>$row['end']],'lessons'=>[]];
                $lesson=json_decode($row['body'],true,512,JSON_THROW_ON_ERROR);
                $days[$row['date']][$slot]['lessons'][$row['body']]=$lesson;
            }
        }
        if (!$base) throw new HttpError(409,'sync_required','No cached school timetables for this week. Ask an admin to synchronize it.');
        ksort($days); $result=[];
        foreach ($days as $date=>$slots) {
            ksort($slots); foreach ($slots as &$slot) $slot['lessons']=array_values($slot['lessons']); unset($slot);
            $result[]=['date'=>$date,'lessonsByPeriod'=>array_values($slots)];
        }
        return ['classId'=>0,'weekStart'=>$base['weekStart'],'weekEnd'=>$base['weekEnd'],'days'=>$result];
    }
}
