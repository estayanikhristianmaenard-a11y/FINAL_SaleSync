<?php
declare(strict_types=1);
namespace SaleSync\Seller;
final class PermissionMiddleware { public function requireMethod(string $method,array $allowed): void { if (!in_array(strtoupper($method),$allowed,true)) throw new \RuntimeException('Permission denied',403); } }
