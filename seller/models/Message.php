<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Message extends BaseModel {
    public function conversations(): array { return $this->rows('SELECT * FROM conversations WHERE store_id=? ORDER BY id DESC',[$this->storeId()]); }
    public function forConversation(int $conversationId): array {
        return $this->rows('SELECT m.id AS message_id,m.id,m.conversation_id,m.sender_type,m.sender_id,COALESCE(m.receiver_id,CASE WHEN m.sender_type=\'seller\' THEN c.customer_id ELSE s.seller_id END) AS receiver_id,m.body AS content,m.body,m.created_at AS sent_date,m.created_at,m.is_read FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN stores s ON s.id=c.store_id WHERE m.conversation_id=? AND c.store_id=? AND s.seller_id=? ORDER BY m.id ASC',[$conversationId,$this->storeId(),$this->sellerId]);
    }
}
