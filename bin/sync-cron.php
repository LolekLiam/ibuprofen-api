<?php
require dirname(__DIR__).'/vendor/autoload.php';
use App\Api\{Config,Storage};
$db=new Storage(new Config());
foreach ($db->query('SELECT key FROM schools')->fetchAll() as $school) $db->enqueue($school['key'],'week',null,0);
echo "Current-week sync jobs queued; run bin/worker.php to process them.\n";
