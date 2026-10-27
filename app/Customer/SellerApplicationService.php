<?php
declare(strict_types=1);
namespace SaleSync\Customer;

use SaleSync\Core\{ApiError,Database,Input};

final class SellerApplicationService
{
    public function __construct(private readonly Database $db,private readonly UploadService $uploads) {}

    public function list(int $userId): array
    {
        $rows=$this->db->all('SELECT * FROM seller_applications WHERE customer_id=? ORDER BY application_id DESC',[$userId]);
        foreach ($rows as &$row) {
            $row['events']=$this->db->all('SELECT status,message,created_at FROM application_events WHERE application_id=? ORDER BY event_id',[$row['application_id']]);
            $row['documents']=$this->db->all('SELECT u.upload_id,u.original_name FROM application_documents d JOIN customer_uploads u USING(upload_id) WHERE d.application_id=?',[$row['application_id']]);
        }
        return $rows;
    }

    public function submit(int $userId,array $input): array
    {
        return $this->db->transaction(function() use($userId,$input) {
            $open=$this->db->one("SELECT application_id,status FROM seller_applications WHERE customer_id=? AND status!='rejected' ORDER BY application_id DESC LIMIT 1",[$userId]);
            if ($open && $open['status']!=='needs_information') throw new ApiError(409,'An application is already pending or approved.');
            $values=[];
            foreach (['store_name'=>100,'business_name'=>150,'business_type'=>80,'contact_name'=>100,'address_text'=>500,'description'=>2000] as $key=>$max) $values[]=Input::text($input,$key,$max);
            $email=Input::email(['email'=>$input['contact_email']??null]);
            $phone=\SaleSync\Auth\RegistrationDetails::phone($input);
            $ids=$input['document_ids']??[];
            if (!is_array($ids)) throw new ApiError(422,'Invalid documents.');
            $files=$this->uploads->owned($userId,$ids,'application');
            foreach ($files as $file) if ($this->db->one('SELECT upload_id FROM application_documents WHERE upload_id=?',[$file['upload_id']])) throw new ApiError(422,'Document has already been submitted.');
            [$store,$business,$type,$contact,$address,$description]=$values;
            $args=[$store,$business,$type,$contact,$email,$phone,$address,$description];
            if ($open) {
                $id=(int)$open['application_id'];
                $this->db->run("UPDATE seller_applications SET store_name=?,business_name=?,business_type=?,contact_name=?,contact_email=?,phone=?,address_text=?,description=?,status='pending',updated_at=strftime('%Y-%m-%dT%H:%M:%SZ','now') WHERE application_id=?",[...$args,$id]);
            } else {
                $this->db->run('INSERT INTO seller_applications(store_name,business_name,business_type,contact_name,contact_email,phone,address_text,description,customer_id) VALUES (?,?,?,?,?,?,?,?,?)',[...$args,$userId]);
                $id=$this->db->id();
            }
            foreach ($files as $file) $this->db->run('INSERT INTO application_documents(application_id,upload_id) VALUES (?,?)',[$id,$file['upload_id']]);
            $this->db->run("INSERT INTO application_events(application_id,actor_id,status,message) VALUES (?,?,'pending','Customer submitted application for management review.')",[$id,$userId]);
            (new NotificationService($this->db))->notify($userId,'Seller application submitted',"Application #$id is awaiting management review.",'Account','application',$id);
            return ['application_id'=>$id,'status'=>'pending'];
        });
    }
}
