<?php
require_once __DIR__.'/../includes/auth.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
function faceReply(int $code, string $message, array $extra=[]): void {
    http_response_code($code);
    echo json_encode(array_merge(['success'=>$code===200,'message'=>$message],$extra));
    exit;
}
if (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)) faceReply(403,'Enrollment is restricted to localhost.');
if (!isLoggedIn() || currentRole()!=='admin') faceReply(403,'Sign in with an Admin account.');
if (!empty(user()['must_change_password'])) faceReply(403,'Change your password before managing enrollment.');
if ($_SERVER['REQUEST_METHOD']!=='POST') faceReply(405,'POST required.');
requireCsrfToken();
require_once __DIR__.'/../includes/face-enrollment-service.php';
$id=filter_var($_POST['employee_id']??null,FILTER_VALIDATE_INT);
$mode=$_POST['mode']??'';
if (!$id || $id<1 || !is_string($mode) || !in_array($mode,['add','replace','remove'],true)) faceReply(422,'Select an employee and valid enrollment operation.');
if ($mode!=='remove' && ($_POST['consent']??'')!=='1') faceReply(422,'Employee consent must be confirmed.');
try {
    $samples=[];
    if ($mode!=='remove') {
        $raw=$_POST['descriptors']??'';
        if (!is_string($raw)||strlen($raw)>40000) throw new InvalidArgumentException('Invalid sample payload.');
        $samples=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        enrollmentSamples($samples);
    }
    $result=manageFaceEnrollment($pdo,(int)user()['user_id'],$id,$mode,$samples,($_POST['confirmed']??'')==='1');
    faceReply(200,$mode==='remove'?'Enrollment removed. Employee and attendance history retained.':'Enrollment saved.',$result);
} catch (JsonException|InvalidArgumentException $e) {
    faceReply(422,'Invalid enrollment request. '.$e->getMessage());
} catch (DomainException $e) {
    faceReply($e->getCode()===403?403:409,$e->getMessage());
} catch (Throwable $e) {
    faceReply(500,'Enrollment could not be saved. No partial changes were retained. Reload enrollment status before retrying.');
}
