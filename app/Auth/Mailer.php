<?php
declare(strict_types=1);

namespace SaleSync\Auth;

use SaleSync\Core\ApiError;

final class Mailer
{
    public function __construct(private readonly array $config) {}

    public function verification(string $email, string $token): void
    {
        // Token stays in the fragment so access logs do not capture it.
        $link = rtrim($this->config['app_url'], '/') . '/#verify-email=' . rawurlencode($token);
        $body = "Verify your SaleSync email within one hour:\n$link\n";
        if ($this->config['mail_driver'] === 'log') {
            $directory = dirname($this->config['database']) . '/mail';
            if (!is_dir($directory)) { mkdir($directory, 0700, true); }
            $file = $directory . '/' . bin2hex(random_bytes(12)) . '.json';
            if (file_put_contents($file, json_encode(['to' => $email, 'subject' => 'Verify your SaleSync email', 'body' => $body], JSON_PRETTY_PRINT), LOCK_EX) === false) {
                throw new ApiError(503, 'Email delivery is unavailable. Try resending later.');
            }
            return;
        }
        if ($this->config['mail_driver'] !== 'mail' || !mail($email, 'Verify your SaleSync email', $body, 'From: ' . $this->config['mail_from'])) {
            throw new ApiError(503, 'Email delivery is unavailable. Try resending later.');
        }
    }
}
