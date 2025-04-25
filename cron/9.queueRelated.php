<?php

//$mt = 2; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

$minute = date('Hi');
while ($minute == date('Hi')) {
    try {
        usleep(100000);
        if ($redis->get("zkb:reinforced") == true) break;
        if ($redis->ping() != 1) connectRedis();

        $key = $redis->spop("queueRelatedSet");
        if ($key == null) { sleep(1); continue; }
        $serial = $redis->get("$key:params");
        if ($serial == null) continue;

        $parameters = unserialize($serial);
        $current = $redis->get($parameters['key']);
        if ($redis->get($parameters['key']) !== false) continue;

        //if ($redis->scard("queueRelatedSet") > 10 && (sizeof($parameters['options']['A']) > 0 || sizeof($parameters['options']['B']) > 0)) continue;
        //if ($redis->scard("queueRelatedSet") > 20) continue;

        if ($redis->get($parameters['key']) != null) continue;
        $kills = Kills::getKills($parameters);
        $summary = Related::buildSummary($kills, $parameters['options']);

        $serial = serialize($summary);
        if ($redis->ping() != 1) connectRedis();
        $redis->setex($parameters['key'], 900, $serial);
        $redis->srem('queueRelatedSet', $key);
        $redis->del("$key:params");

    } catch (Exception $e) {
        Util::out(print_r($e, true));
    }
}
