<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class MessageController extends Controller {
    private function conversations(): ConversationService { return new ConversationService($this->db,$this->sellerId); }
    private function conversationId($id,array $data=[]): int { return FinanceValidator::id($_GET['conversation_id']??$data['conversation_id']??$id,'conversation'); }
    private function unreadFilter(array $data=[]): bool { return filter_var($_GET['unread']??$data['unread']??false,FILTER_VALIDATE_BOOLEAN); }
    public function getInbox($id=null,array $data=[]): array {
        $service=$this->conversations();
        return ['conversations'=>$service->all((string)($_GET['q']??''),$this->unreadFilter()),'unread_count'=>$service->unreadCount()];
    }
    public function getConversations($id=null,array $data=[]): array {
        return $this->conversations()->all((string)($_GET['q']??$data['q']??''),$this->unreadFilter($data));
    }
    public function getConversation($id=null,array $data=[]): array {
        $result=$this->conversations()->conversation($this->conversationId($id,$data));
        $messages=$result['messages'];unset($result['messages']);
        return ['conversation'=>$result,'messages'=>$messages];
    }
    public function getMessages($id=null,array $data=[]): array { return ['messages'=>$this->conversations()->messages($this->conversationId($id,$data))]; }
    public function sendMessage($id=null,array $data=[]): array {
        $conversationId=$this->conversationId($id,$data);
        return ['message'=>(new MessageService($this->db,$this->sellerId))->send($conversationId,MessageValidator::body($data))];
    }
    public function replyMessage($id=null,array $data=[]): array { return $this->sendMessage($id,$data); }
    public function reply($id=null,array $data=[]): array {
        $message=(new MessageService($this->db,$this->sellerId))->send($this->conversationId($id,$data),MessageValidator::body($data));
        return ['message_id'=>(int)$message['message_id']];
    }
    public function markRead($id=null,array $data=[]): array { return $this->conversations()->markRead($this->conversationId($id,$data)); }
    public function markAsRead($id=null,array $data=[]): array { $result=$this->markRead($id,$data);return ['marked_read'=>true,'updated_count'=>$result['marked_read']]; }
    public function getUnreadCount($id=null,array $data=[]): array { return ['unread_count'=>$this->conversations()->unreadCount()]; }
    public function searchConversations($id=null,array $data=[]): array {
        $query=trim((string)($_GET['q']??$data['q']??''));
        if(strlen($query)>100) throw new \InvalidArgumentException('Search query must not exceed 100 characters');
        return ['conversations'=>$this->conversations()->all($query,$this->unreadFilter($data))];
    }
}
