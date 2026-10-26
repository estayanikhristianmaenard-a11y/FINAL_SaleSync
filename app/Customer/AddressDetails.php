<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\{ApiError, Input};

final class AddressDetails
{
    public const COLUMNS = ['address_text','house_number','street','province_code','city_code','barangay_code','postal_code'];

    public static function fromInput(array $input): array
    {
        $house = Input::text($input, 'house_number', 100);
        $street = Input::text($input, 'street', 150);
        $province = Input::text($input, 'province', 10, 10);
        $city = Input::text($input, 'city', 10, 10);
        $barangay = Input::text($input, 'barangay', 10, 10);
        $location = (new LocationDirectory())->resolve($province, $city, $barangay);
        $postalCode = Input::text($input, 'postal_code', 4, 4);
        if (!preg_match('/^[0-9]{4}$/', $postalCode)) {
            throw new ApiError(422, 'Enter a four-digit Philippine postal code.', ['postal_code' => 'Use four digits.']);
        }
        return [
            'address_text' => "$house $street, {$location['barangay']}, {$location['city']}, {$location['province']} $postalCode",
            'house_number' => $house, 'street' => $street, 'province_code' => $province,
            'city_code' => $city, 'barangay_code' => $barangay, 'postal_code' => $postalCode,
        ];
    }

    public static function stored(array $row): array
    {
        $details = [];
        foreach (self::COLUMNS as $key) { $details[$key] = $row[$key] ?? null; }
        return $details;
    }
}
