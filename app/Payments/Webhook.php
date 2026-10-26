<?php
declare(strict_types=1);

namespace SaleSync\Payments;

use SaleSync\Core\ApiError;

final class Webhook
{
    public static function parse(string $body, string $header, string $secret): array
    {
        if ($secret === '') { throw new ApiError(503, 'Payment webhook is not configured.'); }
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) { $parts[$pair[0]] = $pair[1]; }
        }
        $timestamp = $parts['t'] ?? '';
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300 || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $parts['te'] ?? '')) {
            throw new ApiError(401, 'Invalid webhook signature.');
        }
        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $data = $event['data'] ?? [];
        // Support PayMongo's resource envelope and the legacy v1 event envelope.
        $type = $data['attributes']['type'] ?? $data['type'] ?? '';
        $session = $data['attributes']['data'] ?? $data['data'] ?? [];
        return ['type' => $type, 'id' => $data['id'] ?? hash('sha256', $body), 'session' => $session];
    }
}
