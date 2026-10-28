<?php
declare(strict_types=1);
namespace SaleSync\Seller;
use PDO;

final class ReviewService {
    private Review $reviews;
    private ReviewReply $replies;
    public function __construct(private PDO $db,private int $sellerId) {
        $this->reviews=new Review($db,$sellerId);$this->replies=new ReviewReply($db,$sellerId);
    }
    public function all(): array { return $this->reviews->allForStore(); }
    public function get(int $reviewId): array {
        $review=$this->reviews->findForStore($reviewId);
        if(!$review) throw new \RuntimeException('Review not found',404);
        return $review;
    }
    public function productReviews(int $productId): array { return $this->reviews->forProduct($productId); }
    public function ratingSummary(): array { return (new RatingService($this->db))->summary($this->storeId(),$this->sellerId); }
    private function storeId(): int {
        $q=$this->db->prepare('SELECT id FROM stores WHERE seller_id=?');$q->execute([$this->sellerId]);$id=$q->fetchColumn();
        if($id===false) throw new \RuntimeException('Seller store was not found',404);
        return (int)$id;
    }
    public function reply(int $reviewId,string $text,?bool $update): array {
        $this->db->beginTransaction();
        try {
            $review=$this->get($reviewId);
            $hasReply=$review['seller_reply']!==null && $review['seller_reply']!=='';
            if($update===null) $update=$hasReply; // The existing seller-reply UI uses one action for create and edit.
            if($update && ($review['seller_reply']===null || $review['seller_reply']==='')) throw new \RuntimeException('No existing seller reply to update',409);
            if(!$update && $review['seller_reply']!==null && $review['seller_reply']!=='') throw new \RuntimeException('A reply already exists; use updateReply',409);
            if(!$this->replies->setForOwnedReview($reviewId,$text)) throw new \RuntimeException('Review not found',404);
            $title=$update?'Seller Reply Updated':'Seller Reply Added';
            (new NotificationService($this->db))->create($this->storeId(),$update?'seller_reply_updated':'seller_reply_added',$title,'Your reply to review #'.$reviewId.' was '.($update?'updated':'added').'.');
            $this->db->commit();
        } catch (\Throwable $error) {
            if($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $this->get($reviewId);
    }
}
