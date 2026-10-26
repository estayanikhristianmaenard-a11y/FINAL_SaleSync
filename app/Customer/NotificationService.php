<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database};

final class NotificationService
{
    public function __construct(private readonly Database $db) {}

    public function preferences(int $userId): array
    {
        $row = $this->db->one('SELECT orders,messages,promotions FROM notification_preferences WHERE customer_id=?', [$userId]);
        return $row ? array_map(static fn($value): bool => (bool) $value, $row) : ['orders'=>true,'messages'=>true,'promotions'=>false];
    }

    public function savePreferences(int $userId, array $input): array
    {
        $values = $this->preferences($userId);
        foreach ($input as $key=>$value) {
            if (!array_key_exists($key,$values) || !is_bool($value)) { throw new ApiError(422,'Preferences must be boolean orders, messages or promotions values.'); }
            $values[$key] = $value;
        }
        $this->db->run('INSERT INTO notification_preferences(customer_id,orders,messages,promotions) VALUES (?,?,?,?) ON CONFLICT(customer_id) DO UPDATE SET orders=excluded.orders,messages=excluded.messages,promotions=excluded.promotions', [$userId,(int)$values['orders'],(int)$values['messages'],(int)$values['promotions']]);
        return $values;
    }

    public function notify(int $userId, string $title, string $message, string $type='Order', ?string $context=null, ?int $reference=null): void
    {
        $this->db->run('INSERT INTO notifications(user_id,title,message,type,context_type,context_id) VALUES (?,?,?,?,?,?)',[$userId,$title,$message,$type,$context,$reference]);
        $id = $this->db->id();
        $preference = match($type) { 'Order','Return'=>'orders','Message'=>'messages','Marketing'=>'promotions',default=>null };
        if ($preference===null || $this->preferences($userId)[$preference]) {
            $this->db->run('INSERT INTO notification_outbox(notification_id) VALUES (?)',[$id]);
        }
    }
}
