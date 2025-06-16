<?php

use cvweiss\redistools\RedisTimeQueue;

global $mdb, $redis, $adminCharacter, $ip;

$sessID = session_id();

if (sizeof($_SESSION) == 0) {
    if ($redis->get("invalid_login:$ip") >= 5) {
        $redis->del("invalid_login:$ip");
        return $app->render("error.html", ['message' => "OK, that's enough.  There is some sort of session bug between you and zkillboard.  Are you running adblockers or some plugin for your browser that could be interfering? Let's figure this out and come visit us on Discord."]);
    }
    Util::zout("$ip $sessID invalid login session detected at callback, restarting");
    $redis->incr("invalid_login:$ip", 1);
    $redis->expire("invalid_login:$ip", 120);
    // something went wrong and we likely lost their state info
    header('Location: /ccplogin');
    return;
}

$sem = sem_get(3174);
try {
    //Util::zout("$ip $sessID coming through");

    // Using the semaphore helps prevent this code from simultaneously handling multiple
    // logins from the same person because they double/triple clicked the authorize
    // button on CCP's SSO login page.
    sem_acquire($sem);

    // Is the user already logged in somehow? If so, redirect them
    // this should help handle double/triple clicks from ccp's authorization page
    if (@$_SESSION['characterID'] > 0) {
        Util::zout("user is already logged in: " . $_SESSION['characterID']);
        header('Location: /ccpoauth2/', 302);
        return;
    }

    $scopeCount = 0;

    $sso = ZKillSSO::getSSO();
    $code = filter_input(INPUT_GET, 'code');
    $state = filter_input(INPUT_GET, 'state');
    $userInfo = $sso->handleCallback($code, $state, $_SESSION);

    $charID = (int) $userInfo['characterID'];
    $charName = $userInfo['characterName'];
    $scopes = explode(' ', $userInfo['scopes']);
    $refresh_token = $userInfo['refreshToken'];
    $access_token = $userInfo['accessToken'];

    // Lookup the character details in the DB.
    $userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID]);
    if (!isset($userdetails['name'])) {
        if ($userdetails == null) {
            $mdb->save('information', ['type' => 'characterID', 'id' => $charID, 'name' => "character_id $charID"]);
        }
    }
    $mdb->set('information', ['id' => (int) $charID], ['name' => $charName, 'namecheck' => true]);
    $mdb->removeField('information', ['type' => 'characterID', 'id' => $charID], 'lastApiUpdate'); // force an api update

    // Wait for the API update
    unset($userdetails['lastApiUpdate']);
    while (!isset($userdetails['lastApiUpdate'])) {
        usleep(100000); // 1/10th of a second
        $userdetails = $mdb->findDoc('information', ['type' => 'characterID', 'id' => $charID]);
    }
    $corpID = Info::getInfoField("characterID", $charID, "corporationID");

    $redis->setex("recentKillmailActivity:char:$charID", 300, "true");
    $redis->setex("recentKillmailActivity:corp:$corpID", 300, "true");

    // Clear out existing scopes
    if ($charID != $adminCharacter) $mdb->remove("scopes", ['characterID' => $charID]);

    foreach ($scopes as $scope) {
        if ($scope == "publicData") continue;
        $row = ['characterID' => $charID, 'scope' => $scope, 'refreshToken' => $refresh_token, 'oauth2' => true];
        if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $scope]) == 0) {
            try {
                $mdb->save("scopes", $row);
                $scopeCount++;
            } catch (Exception $ex) {}
        }
        switch ($scope) {
            case 'esi-killmails.read_killmails.v1':
                $esi = new RedisTimeQueue('tqApiESI', 3600);
                $esi->remove($charID);
                $esi->add($charID);
                break;
            case 'esi-killmails.read_corporation_killmails.v1':
                $esi = new RedisTimeQueue('tqCorpApiESI', 3600);
                $esi->remove($corpID);
                if ($corpID > 1999999) $esi->add($corpID);
                break;
        }
    }

    // Ensure we have admin character scopes saved, if not, redirect to retrieve them
    if ($charID == $adminCharacter) {
        $neededScopes = ['esi-wallet.read_character_wallet.v1', 'esi-wallet.read_corporation_wallets.v1', 'esi-mail.send_mail.v1'];
        $doRedirect = false;
        foreach ($neededScopes as $neededScope) {
            if ($mdb->count("scopes", ['characterID' => $charID, 'scope' => $neededScope]) == 0) $doRedirect = true;
        }
        if ($doRedirect) {
            $sso = ZKillSSO::getSSO($neededScopes);
            return $app->redirect('Location: ' . $sso->getLoginURL($_SESSION), 302);
        }
    }

    if ($scopeCount == 0) Util::zout("Logged in: $charName ($charID) omitted scopes.", $charID, true);
    else Util::zout("Logged in: $charName ($charID)", $charID, true);
    unset($_SESSION['oauth2State']);

    $key = "login:$charID:" . session_id();
    $redis->setex("$key:refreshToken", (86400 * 14), $refresh_token);
    $redis->setex("$key:accessToken", 1000, $access_token);
    $redis->setex("$key:scopes", (86400 * 14), @$userInfo['scopes']);

    $_SESSION['characterID'] = $charID;
    $_SESSION['characterName'] = $charName;

    // Determine where to redirect the user
    $redirect = '/';
    $sessID = session_id();
    $forward = $redis->get("forward:$sessID");
    $redis->del("forward:$sessID");
    $loginPage = UserConfig::get('loginPage', 'character');
    if ($forward !== null) {
        $redirect = $forward;
    } else {
        $corpID = Info::getInfoField("characterID", $charID, "corporationID");
        $alliID = Info::getInfoField("characterID", $charID, "allianceID");
        if (@$_SESSION['patreon'] == true) {
            unset($_SESSION['patreon']);
            $redirect = '/cache/bypass/login/patreon/';
        }
        elseif ($loginPage == "main") $redirect = "/";
        elseif ($loginPage == 'character') $redirect = "/character/$charID/";
        elseif ($loginPage == 'corporation' && $corpID > 0) $redirect = "/corporation/$corpID/";
        elseif ($loginPage == 'alliance' && $alliID > 0) $redirect = "/alliance/$alliID/";
        else $redirect = "/";
    }
    session_write_close();

    if (@$_SESSION['patreon'] == true) $redirect = '/cache/bypass/login/patreon/';
    if ($redirect == '') $redirect = '/';

    $redis->sadd("queueStatsSet", "characterID:$charID"); // encourage stats calc on newly logged in chars
    header('Location: ' . $redirect, 302);

} catch (Exception $e) {
    $sessid = session_id();
    Util::zout("$ip $sessid Failed login attempt: " . $e->getMessage() . "\n" . print_r($_SESSION, true));
    if ($e->getMessage() == "Invalid state returned - possible hijacking attempt") {
        if ($_SESSION['characterID'] > 0) header('Location: /', 302);
        else $app->render('error.html', ['message' => "Please try logging in again, but don't double/triple click this time. CCP's login form isn't very good at handling multiple clicks... "], 503);
    } elseif ($e->getMessage() == "Undefined array key \"access_token\"") {
        return $app->render('error.html', ['message' => "CCP failed to send access token data, please try logging in again."], 503);

    } else {
        Util::zout(print_r($e, true));
        return $app->render('error.html', ['message' => $e->getMessage()], 503);
    }
} finally {
    sem_release($sem);
}
