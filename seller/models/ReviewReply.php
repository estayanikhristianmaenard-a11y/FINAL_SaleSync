<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReviewReply extends BaseModel {
    public function setForOwnedReview(int $reviewId,string $reply): int {
        return $this->run("UPDATE reviews SET seller_reply=?,reply_date=CURRENT_TIMESTAMP WHERE id=? AND store_id=? AND status IN ('Approved','Published') AND EXISTS(SELECT 1 FROM products p JOIN stores s ON s.id=p.store_id WHERE p.id=reviews.product_id AND p.store_id=reviews.store_id AND s.seller_id=?)",[$reply,$reviewId,$this->storeId(),$this->sellerId]);
    }
}
