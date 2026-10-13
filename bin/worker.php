#!/usr/bin/env php
<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Api\{Config,Storage,Timetables,SyncWorker};
use App\Client\EAsistentClient;
$db=new Storage(new Config()); $worker=new SyncWorker($db,new Timetables($db,new EAsistentClient()));
if (($argv[1]??'')==='--once') { $worker->once(); exit; }
while (true) { if (!$worker->once()) sleep(2); }
