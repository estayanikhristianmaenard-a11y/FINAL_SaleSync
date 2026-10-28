<?php
declare(strict_types=1);
namespace SaleSync\Seller;

final class ReturnEvidence extends BaseModel {
    public function forReturn(int $returnId): array {
        return $this->rows('SELECT e.id,e.return_id,e.path,e.mime_type,e.uploaded_at,e.seller_id FROM return_evidence e JOIN returns r ON r.id=e.return_id AND r.store_id=e.store_id WHERE e.return_id=? AND e.store_id=? ORDER BY e.id',[$returnId,$this->storeId()]);
    }
}
