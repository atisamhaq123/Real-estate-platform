<?php
require_once dirname(__DIR__).'/app/bootstrap.php';
if (PHP_SAPI!=='cli') exit;
$result=\Aurelle\Services\DemoProperties::populate();
echo json_encode($result,JSON_PRETTY_PRINT).PHP_EOL;
echo "Fictional demonstration homes only. Existing listings, accounts, and workflows are preserved. Re-running this command does not duplicate the collection.\n";
