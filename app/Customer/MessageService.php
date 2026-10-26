<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError,Database,Input};

final class MessageService
{
    public function __construct(private readonly Database $db) {}

    public function threads(int $userId): array
    {
        return $this->db->all('SELECT t.thread_id,t.seller_id,s.store_name,(SELECT body FROM customer_messages m WHERE m.thread_id=t.thread_id ORDER BY message_id DESC LIMIT 1) AS last_message,(SELECT created_at FROM customer_messages m WHERE m.thread_id=t.thread_id ORDER BY message_id DESC LIMIT 1) AS last_at,(SELECT COUNT(*) FROM customer_messages m WHERE m.thread_id=t.thread_id AND m.sender_id!=? AND m.read_at IS NULL) AS unread FROM customer_threads t JOIN seller_profiles s ON s.seller_id=t.seller_id WHERE t.customer_id=? ORDER BY COALESCE(last_at,t.created_at) DESC,t.thread_id DESC',[$userId,$userId]);
    }

    public function open(int $userId,int $sellerId): array
    {
        (new CatalogService($this->db))->store($sellerId);
        $this->db->run('INSERT INTO customer_threads(customer_id,seller_id) VALUES (?,?) ON CONFLICT(customer_id,seller_id) DO NOTHING',[$userId,$sellerId]);
        return $this->db->one('SELECT thread_id,seller_id FROM customer_threads WHERE customer_id=? AND seller_id=?',[$userId,$sellerId]);
    }

    public function thread(int $userId,int $threadId,array $query=[]): array
    {
        $thread=$this->db->one('SELECT t.*,s.store_name,s.user_id AS seller_user_id FROM customer_threads t JOIN seller_profiles s ON s.seller_id=t.seller_id WHERE t.thread_id=? AND t.customer_id=?',[$threadId,$userId]) ?? throw new ApiError(404,'Conversation not found.');
        $before=isset($query['before'])?Input::integer($query,'before',1,PHP_INT_MAX):PHP_INT_MAX;
        $rows=$this->db->all('SELECT message_id,sender_id,body,read_at,created_at FROM customer_messages WHERE thread_id=? AND message_id<? ORDER BY message_id DESC LIMIT 51',[$threadId,$before]);
        $thread['has_older']=count($rows)>50;
        $thread['messages']=array_reverse(array_slice($rows,0,50));
        return $thread;
    }

    public function send(int $userId,int $threadId,array $input): array
    {
        $body=Input::text($input,'body',2000); $key=Input::text($input,'client_key',100,16);
        return $this->db->transaction(function () use ($userId,$threadId,$body,$key): array {
            $thread=$this->thread($userId,$threadId);
            $existing=$this->db->one('SELECT * FROM customer_messages WHERE sender_id=? AND client_key=?',[$userId,$key]);
            if ($existing) {
                if ($existing['thread_id']!==$threadId || $existing['body']!==$body) throw new ApiError(409,'This message retry key was already used for another message.');
                return ['message_id'=>$existing['message_id']];
            }
            (new CatalogService($this->db))->store((int)$thread['seller_id']);
            $count=$this->db->one("SELECT COUNT(*) AS count FROM customer_messages WHERE sender_id=? AND created_at>strftime('%Y-%m-%dT%H:%M:%SZ','now','-1 minute')",[$userId])['count'];
            if ($count>=30) throw new ApiError(429,'Please wait a moment before sending more messages.');
            $this->db->run('INSERT INTO customer_messages(thread_id,sender_id,body,client_key) VALUES (?,?,?,?)',[$threadId,$userId,$body,$key]);
            $id=$this->db->id();
            (new NotificationService($this->db))->notify((int)$thread['seller_user_id'],'New customer message','A customer sent a message to your store.','Message','thread',$threadId);
            return ['message_id'=>$id];
        });
    }

    public function read(int $userId,int $threadId): array
    {
        $this->thread($userId,$threadId);
        $this->db->run("UPDATE customer_messages SET read_at=COALESCE(read_at,strftime('%Y-%m-%dT%H:%M:%SZ','now')) WHERE thread_id=? AND sender_id!=?",[$threadId,$userId]);
        return ['read'=>true];
    }
}
