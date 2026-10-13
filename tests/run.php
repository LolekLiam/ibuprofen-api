<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Api\{Config,Storage,AdminAuth,Application,Timetables,SyncWorker};
use App\Client\EAsistentClient;
use App\Parsers\{SchoolPageParser,TimetableParser};
use App\Models\{SchoolMeta,TimetableWeek};
use Slim\Psr7\Factory\ServerRequestFactory;

$fixture=dirname(__DIR__,2).'/app/src/test/resources/';
final class FixtureClient extends EAsistentClient {
    public int $calls=0; public bool $fail=false;
    public function __construct(private string $fixture) {}
    public function getSchoolMeta(string $key): SchoolMeta {
        $meta=SchoolPageParser::parse(file_get_contents($this->fixture.'school.html'),$key);
        $meta->classes=array_slice($meta->classes,0,2); return $meta;
    }
    public function getTimetable(string $schoolKey,int $classId,int $weekId): TimetableWeek {
        $this->calls++; if ($this->fail) throw new RuntimeException('Secret upstream details');
        return TimetableParser::parse($classId,file_get_contents($this->fixture.'timetable-print.html'));
    }
}
function check(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
$count=0;
function test(string $name,callable $operation): void { global $count; $operation(); $count++; echo "PASS $name\n"; }
$dir=sys_get_temp_dir().'/ibuprofen-api-test-'.bin2hex(random_bytes(6));
putenv('IBUPROFEN_ALLOW_HTTP=1'); $config=new Config($dir); $db=new Storage($config); $source=new FixtureClient($fixture);
$service=new Timetables($db,$source); $auth=new AdminAuth($db); $app=Application::create($config,$source);
function request(string $method,string $path,?array $body=null,?string $token=null,string $scheme='http'): array {
    global $app;
    $r=(new ServerRequestFactory())->createServerRequest($method,$scheme.'://localhost'.$path,['REMOTE_ADDR'=>'127.0.0.1']);
    if ($body!==null) { $r=$r->withHeader('Content-Type','application/json'); $r->getBody()->write(json_encode($body)); }
    if ($token!==null) $r=$r->withHeader('Authorization','Bearer '.$token);
    $s=$app->handle($r);
    return [$s->getStatusCode(),json_decode((string)$s->getBody(),true),$s];
}
$key='test-school-key'; $token='';
test('printable parser uses direct table rows and correct dates',function () use ($fixture) {
    $w=TimetableParser::parse(1,file_get_contents($fixture.'timetable-print.html'));
    check(count($w->days)===5 && $w->weekStart==='2026-10-12','Bad dates');
    check(count($w->days[0]->lessonsByPeriod)===9,'Nested table rows were parsed as periods');
    check($w->days[0]->lessonsByPeriod[0]['lessons'][0]->subjectCode==='ITP','Missing plain-text subject');
});
test('week metadata includes actual available week IDs',function () use ($source,$key) { check(in_array(7,$source->getSchoolMeta($key)->allWeekIds,true),'Missing week 7'); });
test('legacy routes cannot run unauthenticated synchronization',function () use ($key) {
    check(request('GET',"/school/$key/sync")[0]===404,'Legacy sync still exposed');
    check(request('POST',"/v1/admin/schools/$key/sync-jobs",['scope'=>'year'])[0]===401,'Sync not protected');
    check(request('GET','/v1/admin/stats')[0]===401,'Stats not protected');
});
test('admin passwords are hashed and bearer tokens are never stored raw',function () use ($auth,$db,&$token) {
    $auth->create('owner','test-password-long-enough');
    $hash=$db->query('SELECT password_hash FROM admins')->fetchColumn(); check(password_verify('test-password-long-enough',$hash),'Bad password hash');
    [$status,$body]=request('POST','/v1/admin/sessions',['username'=>'owner','password'=>'test-password-long-enough']);
    check($status===200,'Login failed'); $token=$body['data']['token'];
    check(strlen($token)===64 && $db->query('SELECT hash FROM sessions')->fetchColumn()!==$token,'Raw token stored');
    check(request('GET','/v1/admin/me',null,$token)[1]['data']['role']==='admin','Admin identity missing');
});
test('class cache avoids repeated upstream calls',function () use ($key,$source) {
    request('GET',"/v1/schools/$key"); $before=$source->calls;
    $a=request('GET',"/v1/schools/$key/timetable?class_id=810899&week=7");
    $b=request('GET',"/v1/schools/$key/timetable?class_id=810899&week=7");
    check($a[0]===200 && $a[1]===$b[1],'Cache changed response'); check($source->calls===$before+1,'Cache miss repeated');
});
test('room and abbreviated-teacher filters retain class labels and coverage',function () use ($key) {
    $teacher=request('GET',"/v1/schools/$key/timetable?teacher=".rawurlencode('J. Plavec').'&week=7');
    $room=request('GET',"/v1/schools/$key/timetable?room=D3.1&week=7");
    check($teacher[0]===200 && $room[0]===200,'Aggregation failed');
    check($teacher[1]['meta']['complete']===false,'Missing classes not reported');
    $lessons=array_merge(...array_map(fn($day)=>array_merge([], ...array_column($day['lessonsByPeriod'],'lessons')),$room[1]['data']['days']));
    check(count($lessons)>0 && $lessons[0]['sourceClassLabel']==='E1A','Source class missing');
});
test('POST queues a deduplicated job without synchronously fetching all classes',function () use ($key,$source,&$token) {
    $before=$source->calls;
    $a=request('POST',"/v1/admin/schools/$key/sync-jobs",['scope'=>'week','week'=>7],$token);
    $b=request('POST',"/v1/admin/schools/$key/sync-jobs",['scope'=>'week','week'=>7],$token);
    check($a[0]===202 && $a[1]['data']['id']===$b[1]['data']['id'],'Job not deduplicated');
    check($source->calls===$before,'Sync ran inside HTTP request');
});
test('worker completes jobs and makes aggregate coverage complete',function () use ($db,$service,$key) {
    check((new SyncWorker($db,$service))->once(),'Worker found no job');
    check($db->query('SELECT status FROM jobs')->fetchColumn()==='succeeded','Job failed');
    check(request('GET',"/v1/schools/$key/timetables?week=7")[1]['meta']['complete']===true,'Coverage incomplete');
});
test('failed refresh preserves the last successful cache atomically',function () use ($service,$source,$key,$db) {
    $meta=$service->meta($key); $cacheKey=$service->cacheKey($meta,810899,7); $before=$db->cache($cacheKey);
    $source->fail=true;
    try { $service->classWeek($meta,810899,7,true); throw new LogicException('Expected failure'); } catch (RuntimeException $e) { check(!$e instanceof LogicException,'Refresh unexpectedly succeeded'); }
    $source->fail=false; check($before===$db->cache($cacheKey),'Failed refresh destroyed cached schedule');
});
test('database failure rolls back cache and lesson replacement together',function () use ($db,$service,$key) {
    $meta=$service->meta($key); $cacheKey=$service->cacheKey($meta,810899,7); $before=$db->cache($cacheKey);
    $count=(int)$db->query('SELECT COUNT(*) FROM lessons WHERE cache_key=?',[$cacheKey])->fetchColumn();
    $db->pdo->exec("CREATE TRIGGER fixture_failure BEFORE INSERT ON lessons BEGIN SELECT RAISE(ABORT,'fixture'); END;");
    try { $service->classWeek($meta,810899,7,true); throw new LogicException('Expected write failure'); }
    catch (PDOException $e) { }
    finally { $db->pdo->exec('DROP TRIGGER fixture_failure'); }
    check($before===$db->cache($cacheKey),'Cache update escaped transaction');
    check($count===(int)$db->query('SELECT COUNT(*) FROM lessons WHERE cache_key=?',[$cacheKey])->fetchColumn(),'Partial lesson replacement survived');
});
test('concurrent refresh lock avoids a duplicate upstream fetch',function () use ($db,$service,$source,$key) {
    $meta=$service->meta($key); $cacheKey=$service->cacheKey($meta,810899,7);
    $handle=fopen($db->config->dataDir.'/'.hash('sha256',$cacheKey).'.lock','c'); flock($handle,LOCK_EX);
    $before=$source->calls;
    try { $service->classWeek($meta,810899,7,true); throw new LogicException('Expected busy refresh'); }
    catch (App\Api\HttpError $e) { check($e->status===503,'Incorrect busy status'); }
    finally { flock($handle,LOCK_UN); fclose($handle); }
    check($before===$source->calls,'Duplicate upstream request made');
});
test('invalid filters, unknown classes, and obsolete force refresh are rejected',function () use ($key) {
    check(request('GET',"/v1/schools/$key/timetable?class_id=810899&teacher=x")[0]===422,'Multiple filters accepted');
    check(request('GET',"/v1/schools/$key/timetable?class_id=810899&force=true")[0]===422,'Unauthenticated force refresh accepted');
    check(request('GET',"/v1/schools/$key/timetable?class_id=99999&week=7")[0]===404,'Unknown class accepted');
    check(request('GET',"/v1/schools/$key/timetable?class_id=810899&week=99")[0]===422,'Invalid week accepted');
});
test('password reset revokes existing sessions',function () use ($auth,&$token) {
    $auth->create('owner','new-test-password-long'); check(request('GET','/v1/admin/me',null,$token)[0]===401,'Old token survived password reset');
});
test('login is rate limited without trusting forwarded client IPs',function () use ($auth) {
    for ($i=0;$i<10;$i++) { try { $auth->login('missing','invalid-password','test-ip'); } catch (App\Api\HttpError $e) { check($e->status===401,'Unexpected login status'); } }
    try { $auth->login('missing','invalid-password','test-ip'); throw new LogicException('Expected throttle'); }
    catch (App\Api\HttpError $e) { check($e->status===429,'Login not throttled'); }
});
test('logout and expiration revoke server-side access',function () use ($auth,$db) {
    $auth->create('temporary-owner','temporary-test-password');
    $session=$auth->login('temporary-owner','temporary-test-password','session-test-ip');
    check(request('DELETE','/v1/admin/sessions/current',null,$session['token'])[0]===200,'Logout failed');
    check(request('GET','/v1/admin/me',null,$session['token'])[0]===401,'Logged-out token still works');
    $session=$auth->login('temporary-owner','temporary-test-password','session-test-ip');
    $db->query('UPDATE sessions SET expires_at=? WHERE hash=?',[time()-1,hash('sha256',$session['token'])]);
    check(request('GET','/v1/admin/me',null,$session['token'])[0]===401,'Expired token still works');
});
test('different sync scopes are queued separately',function () use ($db,$key) {
    $week=$db->enqueue($key,'week',8,1); $year=$db->enqueue($key,'year',null,1);
    check($week['id']!==$year['id'],'Year synchronization was silently replaced by a week job');
});
test('worker resumes interrupted tasks without repeating completed classes',function () use ($db,$service,$source,$key) {
    $job=$db->enqueue($key,'week',7,1); $id=$job['id'];
    $db->query("UPDATE jobs SET status='running',total=2,completed=1 WHERE id=?",[$id]);
    $db->query("INSERT INTO tasks VALUES(?,810899,7,'done')",[$id]);
    $db->query("INSERT INTO tasks VALUES(?,810907,7,'pending')",[$id]);
    $before=$source->calls;
    (new SyncWorker($db,$service))->once();
    check($source->calls===$before+1,'Completed tasks repeated');
    check($db->job($id)['status']==='succeeded' && $db->job($id)['completed']===2,'Recovery failed');
});
test('production admin requires TLS',function () use (&$app,$dir,$source) {
    putenv('IBUPROFEN_ALLOW_HTTP=0'); $app=Application::create(new Config($dir),$source);
    check(request('POST','/v1/admin/sessions',['username'=>'owner','password'=>'new-test-password-long'])[0]===403,'HTTP admin credentials accepted');
    check(request('POST','/v1/admin/sessions',['username'=>'owner','password'=>'new-test-password-long'],null,'https')[0]===200,'HTTPS login rejected');
});
echo "$count tests passed\n";
