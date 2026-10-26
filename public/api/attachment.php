<?php
declare(strict_types=1);

use SaleSync\Core\{ApiError,Database,Input,Session};
use SaleSync\Auth\{AuthService,Mailer};

$config=require dirname(__DIR__,2).'/app/bootstrap.php';
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
try {
    Session::start($config);
    $db=new Database($config['database']);
    $user=(new AuthService($db,new Mailer($config)))->customer();
    $id=Input::integer($_GET,'id',1,PHP_INT_MAX);
    $file=$db->one('SELECT * FROM customer_uploads WHERE upload_id=? AND customer_id=?',[$id,$user['user_id']]) ?? throw new ApiError(404,'Attachment not found.');
    $path=dirname($config['database']).'/attachments/'.$file['storage_name'];
    if (!is_file($path)) throw new ApiError(404,'Attachment not found.');
    header('Content-Type: '.$file['media_type']);
    header('Content-Security-Policy: sandbox');
    header("Content-Disposition: ".($file['media_type']==='application/pdf'?'attachment':'inline')."; filename*=UTF-8''".rawurlencode($file['original_name']));
    header('Content-Length: '.filesize($path)); readfile($path);
} catch (ApiError $error) { http_response_code($error->status); echo $error->getMessage(); }
catch (Throwable $error) { http_response_code(500); echo 'Unable to retrieve attachment.'; }
