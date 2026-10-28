<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;
final class AuthMiddleware {
    public function requireSeller(PDO $db): int {
        $id=filter_var($_SESSION['seller_id']??null,FILTER_VALIDATE_INT); if (!$id || $id<1) throw new \RuntimeException('Authentication required',401);
        $q=$db->prepare('SELECT active FROM sellers WHERE id=?'); $q->execute([$id]); $active=$q->fetchColumn();
        if ($active===false) throw new \RuntimeException('Authentication required',401); if (!(int)$active) throw new \RuntimeException('Seller account is inactive',403); return (int)$id;
    }
}
