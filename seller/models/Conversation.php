<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Conversation extends BaseModel {
    public function allForStore(string $search='',bool $unreadOnly=false): array {
        $storeId=$this->storeId();
        $sql='SELECT c.id AS conversation_id,c.id,c.store_id,s.seller_id,c.customer_id,c.customer_name,c.created_at,c.updated_at,(SELECT m.body FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message,(SELECT m.created_at FROM messages m WHERE m.conversation_id=c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_type<>\'seller\' AND m.is_read=0) AS unread_count,(SELECT COUNT(*) FROM messages m WHERE m.conversation_id=c.id AND m.sender_type<>\'seller\' AND m.is_read=0) AS unread FROM conversations c JOIN stores s ON s.id=c.store_id WHERE c.store_id=? AND s.seller_id=?';
        $args=[$storeId,$this->sellerId];
        if($unreadOnly) $sql.=" AND EXISTS(SELECT 1 FROM messages unread WHERE unread.conversation_id=c.id AND unread.sender_type<>'seller' AND unread.is_read=0)";
        if($search!=='') {
            $sql.=' AND (CAST(c.customer_id AS TEXT) LIKE ? OR COALESCE(c.customer_name,\'\') LIKE ? OR EXISTS(SELECT 1 FROM messages search_message WHERE search_message.conversation_id=c.id AND search_message.body LIKE ?))';
            $term='%'.$search.'%';$args[]=$term;$args[]=$term;$args[]=$term;
        }
        $sql.=' ORDER BY COALESCE((SELECT MAX(m.created_at) FROM messages m WHERE m.conversation_id=c.id),c.updated_at,c.created_at) DESC,c.id DESC';
        return $this->rows($sql,$args);
    }
    public function findForStore(int $conversationId): ?array {
        return $this->one('SELECT c.id AS conversation_id,c.id,c.store_id,s.seller_id,c.customer_id,c.customer_name,c.created_at,c.updated_at FROM conversations c JOIN stores s ON s.id=c.store_id WHERE c.id=? AND c.store_id=? AND s.seller_id=?',[$conversationId,$this->storeId(),$this->sellerId]);
    }
}
