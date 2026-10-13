<?php
namespace App\Api;
use App\Client\EAsistentClient;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Factory\AppFactory;

final class Application {
    public static function create(Config $config, ?EAsistentClient $client = null): \Slim\App {
        $db=new Storage($config); $timetables=new Timetables($db,$client ?? new EAsistentClient()); $auth=new AdminAuth($db);
        $app=AppFactory::create();
        $json=function (Response $response, mixed $data, array $meta=[], int $status=200): Response {
            $response->getBody()->write(json_encode(['data'=>$data,'meta'=>(object)$meta],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            return $response->withStatus($status)->withHeader('Content-Type','application/json')->withHeader('X-Content-Type-Options','nosniff')->withHeader('Cache-Control','no-store');
        };
        $integer=function (mixed $input, int $min, int $max): int {
            $value=filter_var($input,FILTER_VALIDATE_INT);
            if ($value===false || $value<$min || $value>$max) throw new HttpError(422,'invalid_parameter','Invalid numeric parameter.');
            return $value;
        };
        $admin=function (Request $request, Handler $handler) use ($auth,$config): Response {
            if (!$config->allowHttp && $request->getUri()->getScheme()!=='https') throw new HttpError(403,'https_required','Admin connections require HTTPS.');
            return $handler->handle($request->withAttribute('admin',$auth->authenticate($request->getHeaderLine('Authorization'))));
        };
        $app->get('/v1/health',fn(Request $r,Response $s)=>$json($s,['status'=>'ok','version'=>1,'timestamp'=>time()]));
        $app->get('/v1/schools/{key}',fn(Request $r,Response $s,array $a)=>$json($s,$timetables->meta($a['key'])));
        $app->get('/v1/schools/{key}/classes',fn(Request $r,Response $s,array $a)=>$json($s,$timetables->meta($a['key'])['classes']));
        foreach (['teachers'=>'teacher','rooms'=>'room'] as $resource=>$column) {
            $app->get('/v1/schools/{key}/'.$resource,function (Request $r,Response $s,array $a) use ($json,$timetables,$column) {
                $meta=$timetables->meta($a['key']); return $json($s,$timetables->entities($meta,$column));
            });
        }
        $app->get('/v1/schools/{key}/timetables',function (Request $r,Response $s,array $a) use ($json,$timetables,$integer) {
            $q=$r->getQueryParams();
            if (array_diff(array_keys($q),['week'])) throw new HttpError(422,'invalid_parameter','Unknown query parameter.');
            $meta=$timetables->meta($a['key']); $week=$timetables->weekId($meta,isset($q['week']) ? $integer($q['week'],0,53) : null);
            return $json($s,$timetables->cachedWeeks($meta,$week),$timetables->coverage($meta,$week));
        });
        $app->get('/v1/schools/{key}/timetable',function (Request $r,Response $s,array $a) use ($json,$timetables,$integer) {
            $q=$r->getQueryParams();
            if (array_diff(array_keys($q),['week','class_id','teacher','room'])) throw new HttpError(422,'invalid_parameter','Unknown timetable query parameter.');
            $filters=array_intersect(['class_id','teacher','room'],array_keys($q));
            if (count($filters)!==1) throw new HttpError(422,'invalid_filter','Choose exactly one of class_id, teacher, or room.');
            $meta=$timetables->meta($a['key']); $week=$timetables->weekId($meta,isset($q['week']) ? $integer($q['week'],0,53) : null);
            if (isset($q['class_id'])) return $json($s,$timetables->classWeek($meta,$integer($q['class_id'],1,PHP_INT_MAX),$week));
            $column=isset($q['teacher']) ? 'teacher' : 'room';
            if (!is_string($q[$column]) || strlen(trim($q[$column]))<1 || strlen($q[$column])>200) throw new HttpError(422,'invalid_filter','Invalid teacher or room.');
            return $json($s,$timetables->aggregate($meta,$week,$column,trim($q[$column])),$timetables->coverage($meta,$week));
        });
        $app->post('/v1/admin/sessions',function (Request $r,Response $s) use ($auth,$json,$config) {
            if (!$config->allowHttp && $r->getUri()->getScheme()!=='https') throw new HttpError(403,'https_required','Admin connections require HTTPS.');
            $body=$r->getParsedBody();
            if (!is_array($body) || !is_string($body['username']??null) || !is_string($body['password']??null) || strlen($body['username'])>64 || strlen($body['password'])>72)
                throw new HttpError(422,'invalid_credentials','Provide a username and password.');
            return $json($s,$auth->login($body['username'],$body['password'],$r->getServerParams()['REMOTE_ADDR']??'unknown'));
        });
        $app->get('/v1/admin/me',fn(Request $r,Response $s)=>$json($s,['username'=>$r->getAttribute('admin')['username'],'role'=>'admin']))->add($admin);
        $app->delete('/v1/admin/sessions/current',function (Request $r,Response $s) use ($auth,$json) {
            $auth->logout($r->getHeaderLine('Authorization')); return $json($s,['signed_out'=>true]);
        })->add($admin);
        $app->get('/v1/admin/stats',fn(Request $r,Response $s)=>$json($s,$db->stats()))->add($admin);
        $app->get('/v1/admin/schools',fn(Request $r,Response $s)=>$json($s,$db->query('SELECT key,name FROM schools ORDER BY name')->fetchAll()))->add($admin);
        $app->post('/v1/admin/schools',function (Request $r,Response $s) use ($json,$timetables) {
            $key=$r->getParsedBody()['key']??null;
            if (!is_string($key)) throw new HttpError(422,'invalid_school','Provide a school key.');
            return $json($s,$timetables->meta($key,true),[],201);
        })->add($admin);
        $app->get('/v1/admin/jobs',fn(Request $r,Response $s)=>$json($s,$db->query('SELECT * FROM jobs ORDER BY created_at DESC LIMIT 50')->fetchAll()))->add($admin);
        $app->get('/v1/admin/jobs/{id}',function (Request $r,Response $s,array $a) use ($db,$json) {
            $job=$db->job($a['id']); if (!$job) throw new HttpError(404,'job_not_found','Job not found.'); return $json($s,$job);
        })->add($admin);
        $app->post('/v1/admin/schools/{key}/sync-jobs',function (Request $r,Response $s,array $a) use ($db,$timetables,$json,$integer) {
            $meta=$timetables->meta($a['key']); $body=$r->getParsedBody(); $scope=$body['scope']??'week';
            if (!in_array($scope,['week','year'],true)) throw new HttpError(422,'invalid_scope','Scope must be week or year.');
            $week=$scope==='week' ? $timetables->weekId($meta,isset($body['week']) ? $integer($body['week'],0,53) : null) : null;
            return $json($s,$db->enqueue($a['key'],$scope,$week,(int)$r->getAttribute('admin')['id']),[],202);
        })->add($admin);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(function (Request $r,Handler $h) use ($db): Response {
            if (strlen((string)$r->getBody())>16384) throw new HttpError(413,'body_too_large','Request body is too large.');
            if (in_array($r->getMethod(),['POST','PUT','PATCH'],true)) {
                if (strtolower(trim(explode(';',$r->getHeaderLine('Content-Type'))[0])) !== 'application/json')
                    throw new HttpError(415,'json_required','Send an application/json request body.');
                try { json_decode((string)$r->getBody(),true,512,JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new HttpError(400,'invalid_json','Request JSON is malformed.'); }
            }
            if (!$db->consume('public-ip:'.hash('sha256',$r->getServerParams()['REMOTE_ADDR']??'unknown'),120,60))
                throw new HttpError(429,'rate_limited','Too many requests. Try again shortly.');
            return $h->handle($r);
        });
        $errors=$app->addErrorMiddleware(false,true,true);
        $errors->setDefaultErrorHandler(function (Request $request,\Throwable $e) use ($app): Response {
            $status=$e instanceof HttpError ? $e->status : ($e instanceof \Slim\Exception\HttpException ? $e->getCode() : 502);
            $code=$e instanceof HttpError ? $e->errorCode : ($status===404 ? 'not_found' : ($status===405 ? 'method_not_allowed' : 'upstream_error'));
            $message=$e instanceof HttpError ? $e->getMessage() : ($status===404 ? 'Endpoint not found. Use /v1 routes.' : ($status===405 ? 'Method not allowed.' : 'The request could not be completed.'));
            if (!$e instanceof HttpError && !$e instanceof \Slim\Exception\HttpException) error_log('API failure: '.get_class($e));
            $response=$app->getResponseFactory()->createResponse($status);
            $response->getBody()->write(json_encode(['error'=>['code'=>$code,'message'=>$message]],JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type','application/json')->withHeader('Cache-Control','no-store')->withHeader('X-Content-Type-Options','nosniff');
        });
        return $app;
    }
}
