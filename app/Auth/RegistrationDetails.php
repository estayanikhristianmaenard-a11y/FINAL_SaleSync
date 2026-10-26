<?php
declare(strict_types=1);

namespace SaleSync\Auth;

use SaleSync\Core\{ApiError, Input};

final class RegistrationDetails
{
    public static function birthDate(array $input, ?int $minimumAge = null, ?\DateTimeImmutable $today = null): string
    {
        $value = Input::text($input, 'birth_date', 10, 10);
        $timezone = new \DateTimeZone('Asia/Manila');
        $today ??= new \DateTimeImmutable('today', $timezone);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (!$date || $date->format('Y-m-d') !== $value || $date > $today) {
            throw new ApiError(422, 'Enter a valid birthday that is not in the future.', ['birth_date' => 'Use a real past or present date in YYYY-MM-DD format.']);
        }
        if ($minimumAge !== null && $date->diff($today)->y < $minimumAge) {
            throw new ApiError(422, "You must be at least $minimumAge years old to register.", ['birth_date' => "The minimum registration age is $minimumAge."]);
        }
        return $value;
    }

    public static function phone(array $input): string
    {
        $phone = Input::text($input, 'phone', 25, 7);
        $digits = preg_replace('/\D/', '', $phone);
        if (!preg_match('/^\+?[0-9][0-9 ()-]*$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) {
            throw new ApiError(422, 'Enter a valid phone number.', ['phone' => 'Use 7–15 digits, with an optional leading +, spaces, parentheses or hyphens.']);
        }
        return str_starts_with($phone, '+') ? '+' . $digits : $digits;
    }

}
