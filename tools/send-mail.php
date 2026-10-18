<?php
declare(strict_types=1);
require dirname(__DIR__).'/api/integration/mail.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $autoload=dirname(__DIR__).'/vendor/autoload.php';
    if (!is_file($autoload) || !mailEnabled()) throw new RuntimeException('Configure email and run composer install first');
    require $autoload;
    $host=envValue('SMTP_HOST'); $port=envValue('SMTP_PORT','587'); $security=envValue('SMTP_SECURITY','tls');
    if (!ctype_digit($port) || (int)$port<1 || (int)$port>65535 || !in_array($security,['tls','smtps','none'],true)) throw new RuntimeException('Invalid SMTP configuration');
    if ($security==='none' && !in_array($host,['localhost','127.0.0.1','::1'],true)) throw new RuntimeException('Plaintext SMTP is allowed only on loopback');
    $db=database(); $db->exec('DELETE FROM mail_outbox WHERE expires_at<=UTC_TIMESTAMP()');
    $sent=0; $failed=0;
    // Row locks coordinate concurrent workers. Transactions are bounded by SMTP timeout.
    for ($i=0;$i<50;$i++) {
        $db->beginTransaction();
        $row=$db->query('SELECT * FROM mail_outbox WHERE available_at<=UTC_TIMESTAMP() AND expires_at>UTC_TIMESTAMP() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED')->fetch();
        if (!$row) { $db->commit(); break; }
        try {
            $mail=new PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host=$host; $mail->Port=(int)$port;
            $mail->Timeout=15; $mail->Timelimit=20; $mail->CharSet='UTF-8';
            $mail->SMTPSecure=match($security) {'smtps'=>PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,'tls'=>PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,default=>''}; $mail->SMTPAutoTLS=$security!=='none';
            $mail->SMTPAuth=envValue('SMTP_USERNAME')!==''; $mail->Username=envValue('SMTP_USERNAME'); $mail->Password=envValue('SMTP_PASSWORD');
            $mail->setFrom(envValue('MAIL_FROM'),'AlternaTech'); $mail->addAddress($row['recipient']); $mail->Subject=$row['subject']; $mail->Body=$row['body']; $mail->send();
            $db->prepare('DELETE FROM mail_outbox WHERE id=?')->execute([$row['id']]); $sent++;
        } catch (Throwable $e) {
            $attempt=(int)$row['attempts']+1;
            if ($attempt>=5) $db->prepare('DELETE FROM mail_outbox WHERE id=?')->execute([$row['id']]);
            else $db->prepare('UPDATE mail_outbox SET attempts=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND) WHERE id=?')->execute([$attempt,min(3600,60*(2**$attempt)),$row['id']]);
            $failed++; error_log('Mail delivery failed; message id '.(int)$row['id']);
        }
        $db->commit();
    }
    echo "Mail worker: $sent sent, $failed failed.\n"; if ($failed) exit(1);
} catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); fwrite(STDERR,"Mail worker unavailable. Check dependencies, SMTP and database configuration.\n"); exit(1); }
