<?php

$panel = getenv('PELICAN_PATH') ?: dirname(__DIR__, 3).'/pelican-panel';
$loader = require $panel.'/vendor/autoload.php';
$loader->addPsr4('GreyHarbour\\DatabaseViewer\\', dirname(__DIR__).'/src/');
putenv('PELICAN_PATH='.$panel);
putenv('APP_ENV=testing');
putenv('APP_KEY=base64:'.base64_encode(str_repeat('t', 32)));
putenv('DB_CONNECTION=sqlite');
$testDatabase = tempnam(sys_get_temp_dir(), 'database-viewer-test-');
putenv('DB_DATABASE='.$testDatabase);
register_shutdown_function(static fn () => unlink($testDatabase));
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');
