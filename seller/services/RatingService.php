<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class RatingService {
    public function __construct(private PDO $db) {}
    public function summary(int $storeId,int $sellerId): array {
        $q=$this->db->prepare("SELECT COUNT(*) AS total_reviews,AVG(r.rating) AS average_rating FROM reviews r JOIN products p ON p.id=r.product_id AND p.store_id=r.store_id JOIN stores s ON s.id=r.store_id WHERE r.store_id=? AND s.seller_id=? AND r.status IN ('Approved','Published')");
        $q->execute([$storeId,$sellerId]);$summary=$q->fetch(PDO::FETCH_ASSOC)?:['total_reviews'=>0,'average_rating'=>null];
        $distribution=$this->db->prepare("SELECT r.rating,COUNT(*) AS total FROM reviews r JOIN products p ON p.id=r.product_id AND p.store_id=r.store_id JOIN stores s ON s.id=r.store_id WHERE r.store_id=? AND s.seller_id=? AND r.status IN ('Approved','Published') GROUP BY r.rating");
        $distribution->execute([$storeId,$sellerId]);$counts=array_fill(1,5,0);
        foreach($distribution->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[(int)$row['rating']]=(int)$row['total'];
        $formatted=[];for($stars=1;$stars<=5;$stars++)$formatted[(string)$stars]=$counts[$stars];
        return ['average_rating'=>$summary['average_rating']===null?0.0:round((float)$summary['average_rating'],2),'total_reviews'=>(int)$summary['total_reviews'],'rating_distribution'=>$formatted];
    }
}
