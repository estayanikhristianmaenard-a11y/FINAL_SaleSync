<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class ConversationService {
    private Conversation $conversations;
    private Message $messages;
    public function __construct(private PDO $db,private int $sellerId) {
        $this->conversations=new Conversation($db,$sellerId);$this->messages=new Message($db,$sellerId);
    }
    public function all(string $search='',bool $unreadOnly=false): array { return $this->conversations->allForStore($search,$unreadOnly); }
    public function conversation(int $id): array {
        $conversation=$this->conversations->findForStore($id);
        if(!$conversation) throw new \RuntimeException('Conversation not found',404);
        $this->markRead($id); // Opening a thread mirrors the existing inbox's read-on-open behavior.
        $conversation['unread_count']=0;
        $conversation['messages']=$this->messages->forConversation($id);
        return $conversation;
    }
    public function messages(int $id): array {
        if(!$this->conversations->findForStore($id)) throw new \RuntimeException('Conversation not found',404);
        return $this->messages->forConversation($id);
    }
    public function markRead(int $id): array {
        $conversation=$this->conversations->findForStore($id);
        if(!$conversation) throw new \RuntimeException('Conversation not found',404);
        $query=$this->db->prepare("UPDATE messages SET is_read=1 WHERE conversation_id=? AND sender_type<>'seller' AND is_read=0");
        $query->execute([$id]);
        return ['conversation_id'=>$id,'marked_read'=>(int)$query->rowCount()];
    }
    public function unreadCount(): int {
        $store=$this->db->prepare('SELECT id FROM stores WHERE seller_id=?');$store->execute([$this->sellerId]);$storeId=$store->fetchColumn();
        if($storeId===false) throw new \RuntimeException('Seller store was not found',404);
        $query=$this->db->prepare("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id=m.conversation_id JOIN stores s ON s.id=c.store_id WHERE c.store_id=? AND s.seller_id=? AND m.sender_type<>'seller' AND m.is_read=0");
        $query->execute([(int)$storeId,$this->sellerId]);return (int)$query->fetchColumn();
    }
}
