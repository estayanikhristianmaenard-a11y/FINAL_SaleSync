<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;
final class SalesService {public function __construct(private PDO $db){} public function summary(int $store): array{$q=$this->db->prepare("SELECT COALESCE(SUM(oi.quantity*oi.unit_price),0) gross,COUNT(DISTINCT o.id) orders,COALESCE(SUM(oi.quantity),0) units FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.store_id=? AND o.paid=1");$q->execute([$store]);return $q->fetch();} }
