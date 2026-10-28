<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReviewController extends Controller {
    private function reviewService(): ReviewService { return new ReviewService($this->db,$this->sellerId); }
    public function getReviews($id=null,array $data=[]): array { return ['reviews'=>$this->reviewService()->all()]; }
    public function getReview($id=null,array $data=[]): array {
        $reviewId=FinanceValidator::id($id??$_GET['review_id']??$data['review_id']??null,'review');
        return $this->reviewService()->get($reviewId);
    }
    public function getProductReviews($id=null,array $data=[]): array {
        $productId=FinanceValidator::id($_GET['product_id']??$data['product_id']??$id,'product');
        return ['reviews'=>$this->reviewService()->productReviews($productId)];
    }
    public function getStoreRating($id=null,array $data=[]): array { return $this->reviewService()->ratingSummary(); }
    public function replyToReview($id=null,array $data=[]): array {
        $reviewId=FinanceValidator::id($id??$data['review_id']??null,'review');
        return $this->reviewService()->reply($reviewId,ReviewReplyValidator::reply($data),null);
    }
    public function updateReply($id=null,array $data=[]): array {
        $reviewId=FinanceValidator::id($id??$data['review_id']??null,'review');
        return $this->reviewService()->reply($reviewId,ReviewReplyValidator::reply($data),true);
    }
}
