<?php
declare(strict_types=1);
namespace SaleSync\Customer;

use SaleSync\Core\{ApiError,Database,Input};

/** Customer submissions only. Seller review and refund execution belong to the other modules. */
final class ReturnService
{
    public function __construct(private readonly Database $db,private readonly UploadService $uploads,private readonly int $days=7) {}

    public function list(int $userId): array
    {
        $rows=$this->db->all('SELECT r.*,o.order_number,i.product_id,i.product_name_snapshot,i.variant_id,i.seller_id FROM return_cases r JOIN orders o USING(order_id) JOIN order_items i USING(order_item_id) WHERE r.customer_id=? ORDER BY r.case_id DESC',[$userId]);
        foreach ($rows as &$row) {
            $row['events']=$this->db->all('SELECT status,message,created_at FROM return_events WHERE case_id=? ORDER BY event_id',[$row['case_id']]);
            $row['evidence']=$this->db->all('SELECT u.upload_id,u.original_name,u.media_type FROM return_evidence e JOIN customer_uploads u USING(upload_id) WHERE e.case_id=?',[$row['case_id']]);
        }
        return $rows;
    }

    public function submit(int $userId,array $input): array
    {
        $key=Input::text($input,'client_key',100,16);
        return $this->db->transaction(function() use($userId,$input,$key) {
            $existing=$this->db->one('SELECT case_id FROM return_cases WHERE customer_id=? AND client_key=?',[$userId,$key]);
            if ($existing) return $existing;
            $item=Input::integer($input,'order_item_id',1,PHP_INT_MAX);
            $quantity=Input::integer($input,'quantity',1,999);
            $row=$this->db->one("SELECT i.*,o.customer_id,o.status,p.status AS payment_status FROM order_items i JOIN orders o USING(order_id) JOIN payments p USING(order_id) WHERE i.order_item_id=? AND o.customer_id=?",[$item,$userId]);
            if (!$row) throw new ApiError(404,'Purchase item not found.');
            $delivered=$this->db->one("SELECT created_at FROM order_status_history WHERE order_id=? AND status IN('delivered','completed') ORDER BY history_id DESC LIMIT 1",[$row['order_id']]);
            if ($row['payment_status']!=='paid' || !$delivered || $row['status']==='cancelled') throw new ApiError(409,'Returns become available after a paid order is delivered or completed.');
            if (strtotime($delivered['created_at'])<time()-$this->days*86400) throw new ApiError(422,"The {$this->days}-day return request window has ended.");
            $used=$this->db->one("SELECT COALESCE(SUM(quantity),0) AS qty,COALESCE(SUM(eligible_amount),0) AS amount FROM return_cases WHERE order_item_id=? AND status!='refund_denied'",[$item]);
            if ($quantity+$used['qty']>$row['quantity']) throw new ApiError(409,'These units already have a return request.');
            $reason=Input::text($input,'reason',100);
            if (!in_array($reason,['Damaged item','Wrong item','Missing parts','Not as described','Other'],true)) throw new ApiError(422,'Select a return reason.');
            $explanation=Input::text($input,'explanation',3000,10);
            $solution=Input::text($input,'solution',30);
            if (!in_array($solution,['return_refund','refund_only'],true)) throw new ApiError(422,'Select a return solution.');
            $evidence=$this->files($userId,$input);
            $net=$row['line_total']-$row['discount_amount'];
            $amount=min($net-$used['amount'],intdiv($net*$quantity,$row['quantity']));
            if ($quantity+$used['qty']===$row['quantity']) $amount=$net-$used['amount'];
            $this->db->run('INSERT INTO return_cases(customer_id,order_id,order_item_id,quantity,reason,explanation,solution,eligible_amount,client_key) VALUES (?,?,?,?,?,?,?,?,?)',[$userId,$row['order_id'],$item,$quantity,$reason,$explanation,$solution,$amount,$key]);
            $id=$this->db->id();
            $this->event($id,$userId,'requested',$explanation,$evidence);
            $seller=$this->db->one('SELECT user_id FROM seller_profiles WHERE seller_id=?',[$row['seller_id']]);
            (new NotificationService($this->db))->notify((int)$seller['user_id'],'Return request',"Return case #$id needs review.",'Return','return',$id);
            return ['case_id'=>$id];
        });
    }

    public function appeal(int $userId,int $id,array $input): array
    {
        return $this->db->transaction(function() use($userId,$id,$input) {
            $case=$this->db->one('SELECT * FROM return_cases WHERE case_id=? AND customer_id=?',[$id,$userId]);
            if (!$case) throw new ApiError(404,'Return case not found.');
            if ($case['status']!=='rejected') throw new ApiError(409,'Only a rejected request can be appealed.');
            $message=Input::text($input,'explanation',3000,10);
            $files=$this->files($userId,$input);
            $this->db->run("UPDATE return_cases SET status='escalated' WHERE case_id=?",[$id]);
            $this->event($id,$userId,'escalated',$message,$files);
            return ['case_id'=>$id,'status'=>'escalated'];
        });
    }

    private function files(int $userId,array $input): array
    {
        $ids=$input['evidence_ids']??[];
        if (!is_array($ids)) throw new ApiError(422,'Invalid attachment selection.');
        $files=$this->uploads->owned($userId,$ids,'return');
        foreach ($files as $file) if ($this->db->one('SELECT upload_id FROM return_evidence WHERE upload_id=?',[$file['upload_id']])) throw new ApiError(422,'Evidence has already been attached to a case.');
        return $files;
    }

    private function event(int $id,int $userId,string $status,string $message,array $files): void
    {
        $this->db->run('INSERT INTO return_events(case_id,actor_id,status,message) VALUES (?,?,?,?)',[$id,$userId,$status,$message]);
        $event=$this->db->id();
        foreach ($files as $file) $this->db->run('INSERT INTO return_evidence(case_id,upload_id,event_id) VALUES (?,?,?)',[$id,$file['upload_id'],$event]);
    }
}
