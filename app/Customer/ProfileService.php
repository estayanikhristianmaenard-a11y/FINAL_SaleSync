<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Database, Input};

final class ProfileService
{
    public function __construct(private readonly Database $db) {}

    public function update(int $userId, array $input): array
    {
        $name = Input::text($input, 'name', 100);
        $this->db->run('UPDATE users SET name = ? WHERE user_id = ?', [$name, $userId]);
        return ['name' => $name];
    }

    public function addresses(int $userId): array
    {
        return $this->db->all('SELECT address_id,label,recipient_name,phone,address_text,is_default FROM addresses WHERE user_id=? ORDER BY is_default DESC,address_id DESC', [$userId]);
    }

    public function address(int $userId, int $addressId): array
    {
        return $this->db->one('SELECT * FROM addresses WHERE user_id=? AND address_id=?', [$userId, $addressId]) ?? throw new ApiError(404, 'Address not found.');
    }

    public function saveAddress(int $userId, ?int $id, array $input): array
    {
        $fields = [Input::text($input, 'label', 40), Input::text($input, 'recipient_name', 100), Input::text($input, 'phone', 25, 7), Input::text($input, 'address_text', 1000, 10)];
        if (isset($input['is_default']) && !is_bool($input['is_default'])) { throw new ApiError(422, 'is_default must be a boolean.'); }
        return $this->db->transaction(function () use ($userId, $id, $input, $fields): array {
            $existing = $id ? $this->address($userId, $id) : null;
            if (!$id && count($this->addresses($userId)) >= 20) { throw new ApiError(422, 'You can save up to 20 addresses.'); }
            $default = ($input['is_default'] ?? (bool) ($existing['is_default'] ?? false)) || !$this->db->one('SELECT address_id FROM addresses WHERE user_id=? AND is_default=1', [$userId]);
            if ($default) { $this->db->run('UPDATE addresses SET is_default=0 WHERE user_id=?', [$userId]); }
            if ($id) {
                $this->db->run('UPDATE addresses SET label=?,recipient_name=?,phone=?,address_text=?,is_default=? WHERE address_id=? AND user_id=?', [...$fields, (int) $default, $id, $userId]);
            } else {
                $this->db->run('INSERT INTO addresses(label,recipient_name,phone,address_text,is_default,user_id) VALUES (?,?,?,?,?,?)', [...$fields, (int) $default, $userId]);
                $id = $this->db->id();
            }
            // Clearing the default on the current default leaves another address selected.
            if (!$default && $existing && $existing['is_default']) { $this->selectDefault($userId); }
            return $this->address($userId, $id);
        });
    }

    private function selectDefault(int $userId): void
    {
        $this->db->run('UPDATE addresses SET is_default=1 WHERE address_id=(SELECT address_id FROM addresses WHERE user_id=? ORDER BY address_id DESC LIMIT 1)', [$userId]);
    }

    public function deleteAddress(int $userId, int $id): array
    {
        return $this->db->transaction(function () use ($userId, $id): array {
            $address = $this->address($userId, $id);
            $this->db->run('DELETE FROM addresses WHERE address_id=? AND user_id=?', [$id, $userId]);
            if ($address['is_default']) { $this->selectDefault($userId); }
            return ['deleted' => true];
        });
    }

    public function notifications(int $userId, array $query): array
    {
        $page = isset($query['page']) ? Input::integer($query, 'page', 1, 100000) : 1;
        return ['items' => $this->db->all('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id DESC LIMIT 20 OFFSET ' . (($page - 1) * 20), [$userId]), 'page' => $page, 'unread' => $this->db->one('SELECT COUNT(*) AS count FROM notifications WHERE user_id=? AND read_at IS NULL', [$userId])['count']];
    }

    public function readNotification(int $userId, int $id): array
    {
        if (!$this->db->one('SELECT notification_id FROM notifications WHERE notification_id=? AND user_id=?', [$id, $userId])) { throw new ApiError(404, 'Notification not found.'); }
        $this->db->run("UPDATE notifications SET read_at=COALESCE(read_at,strftime('%Y-%m-%dT%H:%M:%SZ','now')) WHERE notification_id=? AND user_id=?", [$id, $userId]);
        return ['read' => true];
    }
}
