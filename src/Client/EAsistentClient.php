<?php
namespace App\Client;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use App\Parsers\SchoolPageParser;
use App\Parsers\TimetableParser;
use App\Models\SchoolMeta;
use App\Models\TimetableWeek;

class EAsistentClient {
    private ClientInterface $client;
    public function __construct(?ClientInterface $client = null) {
        $this->client=$client ?? new Client(['base_uri'=>'https://urniki.easistent.com/', 'timeout'=>20,'connect_timeout'=>5,
            'allow_redirects'=>false,'headers'=>['User-Agent'=>'ibuprofen-api/1.0']]);
    }
    public function getSchoolMeta(string $schoolKey): SchoolMeta {
        return SchoolPageParser::parse((string)$this->client->request('GET','urniki/'.rawurlencode($schoolKey).'/')->getBody(),$schoolKey);
    }
    public function getTimetable(string $schoolKey, int $classId, int $weekId): TimetableWeek {
        return TimetableParser::parse($classId,(string)$this->client->request('GET',"urniki/izpis/".rawurlencode($schoolKey)."/$classId/0/0/0/$weekId/0")->getBody());
    }
}
