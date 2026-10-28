<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;
final class NotificationService {public function __construct(private PDO $db){} public function create(int $store,string $type,string $title,string $body=''): void{$q=$this->db->prepare('INSERT INTO notifications(store_id,type,title,body) VALUES(?,?,?,?)');$q->execute([$store,$type,$title,$body]);} }
