<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database, Input};

final class CatalogService
{
    public function __construct(private readonly Database $db) {}

    private const SELECT = 'SELECT p.product_id,p.seller_id,p.category_id,p.sku,p.name,p.description,p.price,p.stock_qty,p.image_url,p.presentation,s.store_name,c.category_name FROM products p JOIN seller_profiles s ON s.seller_id=p.seller_id JOIN users u ON u.user_id=s.user_id JOIN categories c ON c.category_id=p.category_id';
    public const VISIBLE = "p.status='published' AND s.approval_status='approved' AND u.status='active'";

    public function products(array $query): array
    {
        $page = isset($query['page']) ? Input::integer($query, 'page', 1, 100000) : 1;
        $limit = isset($query['limit']) ? Input::integer($query, 'limit', 1, 100) : 20;
        $where = [self::VISIBLE];
        $values = [];
        if (isset($query['q'])) {
            $q = Input::text($query, 'q', 100, 0);
            $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
            $values = ['%' . $q . '%', '%' . $q . '%'];
        }
        foreach (['category_id', 'seller_id'] as $key) {
            if (isset($query[$key])) { $where[] = "p.$key = ?"; $values[] = Input::integer($query, $key, 1, PHP_INT_MAX); }
        }
        foreach (['min_price' => '>=', 'max_price' => '<='] as $key => $operator) {
            if (isset($query[$key])) { $where[] = "p.price $operator ?"; $values[] = Input::integer($query, $key, 0, 100000000); }
        }
        $sorts = ['newest' => 'p.product_id DESC', 'price_asc' => 'p.price ASC,p.product_id ASC', 'price_desc' => 'p.price DESC,p.product_id DESC', 'name' => 'p.name ASC,p.product_id ASC'];
        $sort = $query['sort'] ?? 'newest';
        if (!is_string($sort) || !isset($sorts[$sort])) { throw new ApiError(422, 'Invalid product sort.'); }
        $filter = ' WHERE ' . implode(' AND ', $where);
        $total = $this->db->one('SELECT COUNT(*) AS count FROM products p JOIN seller_profiles s ON s.seller_id=p.seller_id JOIN users u ON u.user_id=s.user_id' . $filter, $values)['count'];
        return ['items' => array_map($this->decorate(...), $this->db->all(self::SELECT . $filter . ' ORDER BY ' . $sorts[$sort] . ' LIMIT ' . $limit . ' OFFSET ' . (($page - 1) * $limit), $values)), 'page' => $page, 'limit' => $limit, 'total' => $total];
    }

    public function product(int $id): array
    {
        return $this->decorate($this->db->one(self::SELECT . ' WHERE p.product_id = ? AND ' . self::VISIBLE, [$id]) ?? throw new ApiError(404, 'Product not found.'));
    }

    private function decorate(array $product): array
    {
        $product['presentation'] = json_decode($product['presentation'], true, 512, JSON_THROW_ON_ERROR);
        $product['variants'] = $this->db->all('SELECT variant_id,name,attributes,price_delta FROM product_variants WHERE product_id=? ORDER BY rowid', [$product['product_id']]);
        foreach ($product['variants'] as &$variant) { $variant['attributes'] = json_decode($variant['attributes'], true, 512, JSON_THROW_ON_ERROR); }
        return $product;
    }

    public function stores(): array
    {
        $stores = $this->db->all("SELECT s.seller_id,s.store_name,s.description,s.presentation FROM seller_profiles s JOIN users u ON u.user_id=s.user_id WHERE s.approval_status='approved' AND u.status='active' ORDER BY s.seller_id");
        foreach ($stores as &$store) { $store['presentation'] = json_decode($store['presentation'], true, 512, JSON_THROW_ON_ERROR); }
        return $stores;
    }

    public function categories(): array
    {
        return $this->db->all('SELECT * FROM categories ORDER BY category_name');
    }

    public function store(int $id): array
    {
        foreach ($this->stores() as $store) { if ($store['seller_id'] === $id) { return $store; } }
        throw new ApiError(404, 'Store not found.');
    }
}
