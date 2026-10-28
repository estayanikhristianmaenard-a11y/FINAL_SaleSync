<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class NotificationController extends Controller {
 public function getNotifications($id=null,array $d=[]): array{return $this->rows('SELECT * FROM notifications WHERE store_id=? ORDER BY id DESC LIMIT 200',[$this->storeId()]);}
 public function markRead($id=null,array $d=[]): array{$id=$this->requiredId($id??$d['notification_id']??null);$this->owned('notifications',$id);$this->run('UPDATE notifications SET is_read=1 WHERE id=? AND store_id=?',[$id,$this->storeId()]);return ['marked_read'=>true];}
 public function markAllRead($id=null,array $d=[]): array{$this->run('UPDATE notifications SET is_read=1 WHERE store_id=?',[$this->storeId()]);return ['marked_read'=>true];}
}
