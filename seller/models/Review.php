<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class Review extends BaseModel {
    private const SELECT='SELECT r.id AS id,r.id AS review_id,r.product_id,p.name AS product_name,s.seller_id,r.customer_id,r.rating,r.review_text,r.created_at AS review_date,r.seller_reply,r.reply_date,r.status FROM reviews r JOIN products p ON p.id=r.product_id AND p.store_id=r.store_id JOIN stores s ON s.id=r.store_id';
    public function allForStore(): array {
        return $this->rows(self::SELECT." WHERE r.store_id=? AND s.seller_id=? AND r.status IN ('Approved','Published') ORDER BY r.created_at DESC,r.id DESC",[$this->storeId(),$this->sellerId]);
    }
    public function findForStore(int $reviewId): ?array {
        return $this->one(self::SELECT." WHERE r.id=? AND r.store_id=? AND s.seller_id=? AND r.status IN ('Approved','Published')",[$reviewId,$this->storeId(),$this->sellerId]);
    }
    public function forProduct(int $productId): array {
        $storeId=$this->storeId();
        $product=$this->one('SELECT id FROM products WHERE id=? AND store_id=?',[$productId,$storeId]);
        if(!$product) throw new \RuntimeException('Product not found',404);
        return $this->rows(self::SELECT." WHERE r.product_id=? AND r.store_id=? AND s.seller_id=? AND r.status IN ('Approved','Published') ORDER BY r.created_at DESC,r.id DESC",[$productId,$storeId,$this->sellerId]);
    }
}
