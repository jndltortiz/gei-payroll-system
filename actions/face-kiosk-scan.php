<?php
require_once __DIR__.'/../includes/face-kiosk-guard.php';
require_once __DIR__.'/../includes/face-kiosk-service.php';
header('Content-Type: application/json');
function kioskReply(int $code,array $body): void {http_response_code($code);echo json_encode($body);exit;}
if($_SERVER['REQUEST_METHOD']!=='POST')kioskReply(405,['message'=>'POST required.']);
requireCsrfToken();session_write_close();
try {
    // Separate from login throttling; the same IP cannot reset this by clearing cookies.
    $ip=$_SERVER['REMOTE_ADDR'];$pdo->beginTransaction();
    $pdo->prepare('INSERT IGNORE INTO kiosk_rate_limits(client_ip,window_started_at,attempts) VALUES (?,NOW(),0)')->execute([$ip]);
    $q=$pdo->prepare('SELECT *,TIMESTAMPDIFF(SECOND,window_started_at,NOW()) age FROM kiosk_rate_limits WHERE client_ip=? FOR UPDATE');$q->execute([$ip]);$limit=$q->fetch();
    if((int)$limit['age']>=60){$pdo->prepare('UPDATE kiosk_rate_limits SET attempts=1,window_started_at=NOW() WHERE client_ip=?')->execute([$ip]);}
    elseif((int)$limit['attempts']>=20){$pdo->commit();header('Retry-After: 60');kioskReply(429,['message'=>'Too many scans. Wait one minute.']);}
    else{$pdo->prepare('UPDATE kiosk_rate_limits SET attempts=attempts+1 WHERE client_ip=?')->execute([$ip]);}
    $pdo->commit();
    $action = $_POST['action'] ?? '';
    if (!is_string($action) || !in_array($action, ['time_in', 'time_out'], true)) {
        kioskReply(422, ['result'=>'invalid_action', 'message'=>'Choose Time In or Time Out before scanning.']);
    }
    $raw=$_POST['descriptor']??'';
    if(!is_string($raw)||strlen($raw)>10000)kioskReply(422,['message'=>'Invalid descriptor.']);
    $values=json_decode($raw,true);
    if(!is_array($values)||!array_is_list($values)||count($values)!==128)kioskReply(422,['message'=>'Expected 128 descriptor numbers.']);
    foreach($values as $v)if((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||abs($v)>10)kioskReply(422,['message'=>'Invalid descriptor values.']);
    $pdo->beginTransaction();$result=kioskScan($pdo,$values,$action);$pdo->commit();kioskReply(200,$result);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();kioskReply(500,['message'=>'Scan could not be recorded. Please ask admin to check before retrying.']);}
