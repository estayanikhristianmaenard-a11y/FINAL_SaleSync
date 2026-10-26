<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
$config=require dirname(__DIR__).'/app/bootstrap.php';
$db=new SaleSync\Core\Database($config['database']);
$service=new SaleSync\Customer\NotificationService($db);
$mailer=new SaleSync\Auth\Mailer($config);
$lock=fopen(dirname($config['database']).'/notification-worker.lock','c');
if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {fwrite(STDERR,"Another notification worker is running.\n");exit(1);}
$count=0;
foreach ($db->all("SELECT o.outbox_id,n.*,u.email,u.status AS account_status FROM notification_outbox o JOIN notifications n USING(notification_id) JOIN users u USING(user_id) WHERE o.status IN('queued','failed') AND o.attempts<3 ORDER BY outbox_id LIMIT 100") as $row) {
    $key=match($row['type']) {'Order','Return'=>'orders','Message'=>'messages','Marketing'=>'promotions',default=>null};
    $status='skipped';$error=null;
    if ($row['account_status']==='active' && ($key===null || $service->preferences((int)$row['user_id'])[$key])) {
        try {
            $mailer->notification($row['email'],$row['title'],$row['message']."\n\nOpen SaleSync: ".$config['app_url']);
            $status=$config['mail_driver']==='log'?'logged':'sent';
        } catch (Throwable $failure) {$status='failed';$error=mb_substr($failure->getMessage(),0,300);}
    }
    $db->run('UPDATE notification_outbox SET status=?,attempts=attempts+1,last_error=? WHERE outbox_id=?',[$status,$error,$row['outbox_id']]);$count++;
}
flock($lock,LOCK_UN);fclose($lock);
echo "Processed $count notification(s). Driver: {$config['mail_driver']}.\n";
