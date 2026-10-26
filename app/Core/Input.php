<?php
declare(strict_types=1);

namespace SaleSync\Core;

final class Input
{
    public static function text(array $input, string $key, int $max = 255, int $min = 1): string
    {
        $value = $input[$key] ?? null;
        if (!is_string($value) || mb_strlen(trim($value)) < $min || mb_strlen(trim($value)) > $max) {
            throw new ApiError(422, 'Please check the submitted fields.', [$key => "Use $min to $max characters."]);
        }
        return trim($value);
    }

    public static function integer(array $input, string $key, int $min = 1, int $max = 999): int
    {
        $value = $input[$key] ?? null;
        if (is_bool($value) || !is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) {
            throw new ApiError(422, 'Please check the submitted fields.', [$key => "Use an integer from $min to $max."]);
        }
        return (int) $value;
    }

    public static function email(array $input): string
    {
        $email = mb_strtolower(self::text($input, 'email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError(422, 'Enter a valid email address.', ['email' => 'Invalid email.']);
        }
        return $email;
    }

    public static function password(array $input, int $minimum = 10): string
    {
        $value = $input['password'] ?? null;
        // Bcrypt has a 72-byte limit. Preserve intentional spaces and reject NULs.
        if (!is_string($value) || mb_strlen($value) < $minimum || strlen($value) > 72 || str_contains($value, "\0")) {
            throw new ApiError(422, 'Please check the submitted fields.', ['password' => "Use at least $minimum characters and at most 72 bytes."]);
        }
        return $value;
    }
}
