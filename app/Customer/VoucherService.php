<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError,Database,Input};

final class VoucherService
{
    public function __construct(private readonly Database $db) {}

    public function list(int $userId): array
    {
        return $this->db->all('SELECT v.*,c.claim_id,r.order_id AS used_order_id FROM vouchers v LEFT JOIN voucher_claims c ON c.voucher_id=v.voucher_id AND c.customer_id=? LEFT JOIN voucher_redemptions r ON r.claim_id=c.claim_id WHERE v.active=1 OR c.claim_id IS NOT NULL ORDER BY v.expires_at DESC,v.voucher_id',[$userId]);
    }

    private function eligible(array $voucher): void
    {
        $now=gmdate('Y-m-d\TH:i:s\Z');
        if (!$voucher['active'] || $voucher['starts_at']>$now || $voucher['expires_at']<=$now) { throw new ApiError(422,'This voucher is inactive, not started or expired.'); }
        $used=$this->db->one('SELECT COUNT(*) AS count FROM voucher_redemptions WHERE voucher_id=?',[$voucher['voucher_id']])['count'];
        if ($voucher['usage_limit']!==null && $used>=$voucher['usage_limit']) { throw new ApiError(409,'This voucher has reached its usage limit.'); }
    }

    public function claim(int $userId,int $voucherId): array
    {
        return $this->db->transaction(function () use ($userId,$voucherId): array {
            $voucher=$this->db->one('SELECT * FROM vouchers WHERE voucher_id=?',[$voucherId]) ?? throw new ApiError(404,'Voucher not found.');
            $this->eligible($voucher);
            $this->db->run('INSERT INTO voucher_claims(customer_id,voucher_id) VALUES (?,?) ON CONFLICT(customer_id,voucher_id) DO NOTHING',[$userId,$voucherId]);
            return ['claimed'=>true];
        });
    }

    public function apply(int $userId,array $quote,array $ids): array
    {
        if (count($ids)>102) { throw new ApiError(422,'Too many vouchers.'); }
        $selected=[];
        foreach ($ids as $value) {
            $id=Input::integer(['voucher_id'=>$value],'voucher_id',1,PHP_INT_MAX);
            if (isset($selected[$id])) { throw new ApiError(422,'Do not apply the same voucher twice.'); }
            $row=$this->db->one('SELECT v.*,c.claim_id,r.redemption_id FROM vouchers v JOIN voucher_claims c ON c.voucher_id=v.voucher_id LEFT JOIN voucher_redemptions r ON r.claim_id=c.claim_id WHERE v.voucher_id=? AND c.customer_id=?',[$id,$userId]);
            if (!$row || $row['redemption_id']) { throw new ApiError(422,'Claim an unused voucher before applying it.'); }
            $this->eligible($row); $selected[$id]=$row;
        }
        uasort($selected,static fn($a,$b)=>['shop'=>0,'platform'=>1,'shipping'=>2][$a['scope']]<=>['shop'=>0,'platform'=>1,'shipping'=>2][$b['scope']]);
        $discounts=array_fill(0,count($quote['items']),0); $scopes=[]; $applied=[]; $shippingDiscount=0;
        foreach ($selected as $voucher) {
            $scope=$voucher['scope'].':'.($voucher['seller_id'] ?? 'all');
            if (isset($scopes[$scope])) { throw new ApiError(422,'Use one voucher per shop, one platform voucher and one shipping voucher.'); }
            $scopes[$scope]=true; $weights=[];
            foreach ($quote['items'] as $index=>$item) {
                if ($voucher['scope']!=='shop' || $item['seller_id']===$voucher['seller_id']) $weights[$index]=$item['line_total']-$discounts[$index];
            }
            $merchandise=array_sum($weights);
            if ($merchandise<$voucher['minimum_spend'] || !$merchandise) { throw new ApiError(422,'The selected items do not meet this voucher’s minimum spend or shop.'); }
            $base=$voucher['scope']==='shipping'?$quote['shipping_fee']:$merchandise;
            if ($base<=0) { throw new ApiError(422,'This basket has no eligible shipping charge.'); }
            $amount=$voucher['kind']==='fixed'?$voucher['value']:intdiv($base*$voucher['value'],100);
            $amount=min($base,$amount,$voucher['max_discount'] ?? PHP_INT_MAX);
            if ($amount<=0) { throw new ApiError(422,'This voucher does not discount the selected basket.'); }
            if ($voucher['scope']==='shipping') $shippingDiscount=$amount;
            else foreach (self::allocate($amount,$weights) as $index=>$value) $discounts[$index]+=$value;
            $applied[]=['voucher_id'=>$voucher['voucher_id'],'claim_id'=>$voucher['claim_id'],'name'=>$voucher['name'],'code'=>$voucher['code'],'scope'=>$voucher['scope'],'seller_id'=>$voucher['seller_id'],'discount'=>$amount];
        }
        foreach ($quote['items'] as $index=>&$item) $item['discount_amount']=$discounts[$index];
        unset($item);
        $quote['merchandise_discount']=array_sum($discounts); $quote['shipping_discount']=$shippingDiscount;
        $quote['total']=$quote['subtotal']+$quote['shipping_fee']-$quote['merchandise_discount']-$shippingDiscount;
        $quote['applied_vouchers']=$applied;
        return $quote;
    }

    private static function allocate(int $amount,array $weights): array
    {
        $total=array_sum($weights); $allocation=[];
        foreach ($weights as $key=>$weight) $allocation[$key]=min($weight,(int)floor($amount*($weight/$total)));
        $remaining=$amount-array_sum($allocation);
        foreach ($allocation as $key=>&$value) {
            if ($remaining>0) { $extra=min($remaining,$weights[$key]-$value); $value+=$extra; $remaining-=$extra; }
        }
        unset($value);
        return $allocation;
    }

    public function redeem(int $orderId,array $applied): void
    {
        // Caller holds the checkout write transaction; UNIQUE(claim_id) prevents repeat spending.
        foreach ($applied as $voucher) $this->db->run('INSERT INTO voucher_redemptions(claim_id,voucher_id,order_id,discount,scope,seller_id) VALUES (?,?,?,?,?,?)',[$voucher['claim_id'],$voucher['voucher_id'],$orderId,$voucher['discount'],$voucher['scope'],$voucher['seller_id']]);
    }
}
