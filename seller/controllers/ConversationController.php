<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ConversationController extends Controller {
    private function service(): ConversationService { return new ConversationService($this->db,$this->sellerId); }
    private function id($id,array $data=[]): int { return FinanceValidator::id($_GET['conversation_id']??$data['conversation_id']??$id,'conversation'); }
    private function unreadFilter(array $data=[]): bool { return filter_var($_GET['unread']??$data['unread']??false,FILTER_VALIDATE_BOOLEAN); }
    public function getInbox($id=null,array $data=[]): array {
        $service=$this->service();
        return ['conversations'=>$service->all((string)($_GET['q']??''),$this->unreadFilter()),'unread_count'=>$service->unreadCount()];
    }
    public function getConversations($id=null,array $data=[]): array {
        return ['conversations'=>$this->service()->all((string)($_GET['q']??$data['q']??''),$this->unreadFilter($data))];
    }
    public function getConversation($id=null,array $data=[]): array { return $this->service()->conversation($this->id($id,$data)); }
    public function getMessages($id=null,array $data=[]): array { return ['messages'=>$this->service()->messages($this->id($id,$data))]; }
    public function markRead($id=null,array $data=[]): array { return $this->service()->markRead($this->id($id,$data)); }
    public function getUnreadCount($id=null,array $data=[]): array { return ['unread_count'=>$this->service()->unreadCount()]; }
    public function searchConversations($id=null,array $data=[]): array {
        $query=trim((string)($_GET['q']??$data['q']??''));
        if(strlen($query)>100) throw new \InvalidArgumentException('Search query must not exceed 100 characters');
        return ['conversations'=>$this->service()->all($query,$this->unreadFilter($data))];
    }
}
