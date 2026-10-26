<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError,Database,Input};

final class UploadService
{
    public function __construct(private readonly Database $db,private readonly array $config) {}

    public function save(int $userId,array $input): array
    {
        $purpose=Input::text($input,'purpose',20);
        if (!in_array($purpose,['return','application'],true)) throw new ApiError(422,'Unknown attachment purpose.');
        $name=basename(str_replace('\\','/',Input::text($input,'name',200)));
        $encoded=Input::text($input,'content',2800000);
        $bytes=base64_decode($encoded,true);
        if ($bytes===false || strlen($bytes)>2097152 || strlen($bytes)<8) throw new ApiError(422,'Upload an image or application PDF no larger than 2 MiB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (in_array($mime,['image/jpeg','image/png','image/webp'],true)) {
            $size=@getimagesizefromstring($bytes);
            if (!$size || $size[0]>12000 || $size[1]>12000) throw new ApiError(422,'Invalid image or image dimensions exceed 12,000 pixels.');
        } elseif (!($purpose==='application' && $mime==='application/pdf' && str_starts_with($bytes,'%PDF-'))) {
            throw new ApiError(422,'Return evidence accepts JPEG, PNG or WebP images; application documents may also be PDF.');
        }
        $recent=$this->db->one("SELECT COUNT(*) AS count FROM customer_uploads WHERE customer_id=? AND created_at>strftime('%Y-%m-%dT%H:%M:%SZ','now','-1 hour')",[$userId])['count'];
        if ($recent>=30) throw new ApiError(429,'Attachment upload limit reached. Try again later.');
        $directory=dirname($this->config['database']).'/attachments';
        if (!is_dir($directory)) mkdir($directory,0700,true);
        $storage=bin2hex(random_bytes(24)).'.blob';
        if (file_put_contents($directory.'/'.$storage,$bytes,LOCK_EX)===false) throw new ApiError(503,'Attachment storage is unavailable.');
        try {
            $this->db->run('INSERT INTO customer_uploads(customer_id,storage_name,original_name,media_type,byte_size,purpose) VALUES (?,?,?,?,?,?)',[$userId,$storage,$name,$mime,strlen($bytes),$purpose]);
        } catch (\Throwable $error) { unlink($directory.'/'.$storage); throw $error; }
        return ['upload_id'=>$this->db->id(),'name'=>$name,'media_type'=>$mime,'byte_size'=>strlen($bytes)];
    }

    public function owned(int $userId,array $ids,string $purpose): array
    {
        if (count($ids)>5) throw new ApiError(422,'Attach up to five files.');
        $files=[];
        foreach ($ids as $value) {
            $id=Input::integer(['upload_id'=>$value],'upload_id',1,PHP_INT_MAX);
            $file=$this->db->one('SELECT upload_id,original_name,media_type,byte_size FROM customer_uploads WHERE upload_id=? AND customer_id=? AND purpose=?',[$id,$userId,$purpose]);
            if (!$file || isset($files[$id])) throw new ApiError(422,'Attachment is invalid, duplicated or belongs to another account.');
            $files[$id]=$file;
        }
        return array_values($files);
    }
}
