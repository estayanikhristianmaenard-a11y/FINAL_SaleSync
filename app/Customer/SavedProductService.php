<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\Database;

final class SavedProductService
{
    public function __construct(private readonly Database $db) {}

    public function list(int $userId): array
    {
        return $this->db->all('SELECT p.product_id,p.name,p.seller_id,p.sku,p.price,p.stock_qty,p.image_url,p.presentation,s.store_name,c.category_name,sp.created_at,('.CatalogService::VISIBLE.') AS available FROM saved_products sp JOIN products p ON p.product_id=sp.product_id JOIN seller_profiles s ON s.seller_id=p.seller_id JOIN users u ON u.user_id=s.user_id JOIN categories c ON c.category_id=p.category_id WHERE sp.customer_id=? ORDER BY sp.created_at DESC,p.product_id DESC',[$userId]);
    }

    public function save(int $userId,int $productId): array
    {
        (new CatalogService($this->db))->product($productId);
        $this->db->run('INSERT INTO saved_products(customer_id,product_id) VALUES (?,?) ON CONFLICT(customer_id,product_id) DO NOTHING',[$userId,$productId]);
        return ['saved'=>true];
    }

    public function remove(int $userId,int $productId): array
    {
        $this->db->run('DELETE FROM saved_products WHERE customer_id=? AND product_id=?',[$userId,$productId]);
        return ['saved'=>false];
    }
}
