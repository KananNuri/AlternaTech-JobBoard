<?php
declare(strict_types=1);
require_once __DIR__.'/common.php';
require_once __DIR__.'/mail.php';
methods(['POST']);
try {
    $body=jsonBody(); $user=currentUser(false);
    // This read creates a fresh CSRF token for a guest; get it from /api/auth/csrf first.
    csrf();
    $adId=positiveId($GLOBALS['route_ad_id']);
    if (isset($body['advertisement_id']) && $body['advertisement_id']!==$adId) reply(422,['error'=>'Advertisement ID must match the URL']);
    if (array_diff(array_keys($body),['advertisement_id','firstname','lastname','email','phone','message'])) reply(422,['error'=>'Unsupported application fields']);
    $message=textField($body,'message',20000,false); $db=database();
    $q=$db->prepare('SELECT id FROM advertisements WHERE id=?'); $q->execute([$adId]);
    if (!$q->fetch()) reply(404,['error'=>'Advertisement not found']);
    if ($user) $person=(int)$user['person_id'];
    else {
        $first=textField($body,'firstname',100); $last=textField($body,'lastname',100); $email=textField($body,'email',150); $phone=textField($body,'phone',30,false);
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) reply(422,['error'=>'Valid email required']);
    }
    $db->beginTransaction();
    if (!$user) {
        // Guest data never overwrites or impersonates an existing account/profile.
        $q=$db->prepare('INSERT INTO people (firstname,lastname,email,phone) VALUES (?,?,?,?)'); $q->execute([$first,$last,$email,$phone]); $person=(int)$db->lastInsertId();
    }
    $q=$db->prepare('INSERT INTO applications (person_id,advertisement_id,message) VALUES (?,?,?)'); $q->execute([$person,$adId,$message]); $id=(int)$db->lastInsertId();
    notifyApplication($db,$adId);
    $db->commit(); reply(201,['id'=>$id,'person_id'=>$person,'advertisement_id'=>$adId,'message'=>$message,'status'=>'pending']);
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); dbFailure($e); }
