<?php
require dirname(__DIR__).'/vendor/autoload.php';
\App\Api\Application::create(new \App\Api\Config())->run();
