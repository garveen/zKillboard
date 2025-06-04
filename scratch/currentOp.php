<?php

require_once "../init.php";

global $mongoServer, $mongoPort, $mongoConnString, $debug;

$mongoClient = null;

if ($mongoConnString == null) $mongoConnString = "mongodb://$mongoServer:$mongoPort";
$mongoClient = new MongoClient($mongoConnString, ['connectTimeoutMS' => 7200000, 'socketTimeoutMS' => 7200000]);
$admin = $mongoClient->selectDB('admin');
$r = $admin->command(['currentOp' => []]);
$running = $r['inprog'];
$high = 0;
foreach ($running as $x) {
    if (@$x['microsecs_running'] > 15000000) {
        print_r($x['command']);
        $high++;
    }
}
Util::out("$high commands over threshold");
