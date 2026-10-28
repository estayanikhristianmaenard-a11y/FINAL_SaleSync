<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class MessageService {
    public function __construct(private PDO $db,private ?int $sellerId=null) {}
    public function send(int $conversationId,string $body): array {
        if(!$this->sellerId) throw new \RuntimeException('Seller authentication is required',401);
        $storeQuery=$this->db->prepare('SELECT id FROM stores WHERE seller_id=?');$storeQuery->execute([$this->sellerId]);$storeId=$storeQuery->fetchColumn();
        if($storeId===false) throw new \RuntimeException('Seller store was not found',404);
        $conversationQuery=$this->db->prepare('SELECT id,customer_id FROM conversations WHERE id=? AND store_id=?');
        $conversationQuery->execute([$conversationId,(int)$storeId]);$conversation=$conversationQuery->fetch(PDO::FETCH_ASSOC);
        if(!$conversation) throw new \RuntimeException('Conversation not found',404);
        $this->db->beginTransaction();
        try {
            $insert=$this->db->prepare("INSERT INTO messages(conversation_id,sender_type,sender_id,receiver_id,body) VALUES(?,'seller',?,?,?)");
            $insert->execute([$conversationId,$this->sellerId,(int)$conversation['customer_id'],$body]);
            $messageId=(int)$this->db->lastInsertId();
            $this->db->commit();
        } catch(\Throwable $error) {
            if($this->db->inTransaction())$this->db->rollBack();
            throw $error;
        }
        $q=$this->db->prepare('SELECT id AS message_id,id,conversation_id,sender_type,sender_id,receiver_id,body AS content,body,created_at AS sent_date,created_at,is_read FROM messages WHERE id=? AND conversation_id=?');
        $q->execute([$messageId,$conversationId]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
    }
    /** Legacy service entry point retained for callers using reply(). */
    public function reply(int $conversationId,int $sender,string $body): int {
        $result=(new self($this->db,$sender))->send($conversationId,$body);
        return (int)$result['message_id'];
    }
}
