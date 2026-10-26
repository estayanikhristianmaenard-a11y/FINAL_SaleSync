<?php
declare(strict_types=1);

namespace SaleSync\Customer;

use SaleSync\Core\ApiError;

final class LocationDirectory
{
    private static ?array $data = null;

    private function data(): array
    {
        self::$data ??= json_decode(file_get_contents(dirname(__DIR__, 2) . '/database/reference/ph-locations.json'), true, 512, JSON_THROW_ON_ERROR);
        return self::$data;
    }

    public function areas(): array
    {
        return ['items' => $this->data()['areas'], 'source' => $this->data()['metadata']];
    }

    public function cities(string $areaCode): array
    {
        $data = $this->data();
        if (!isset($data['cities_by_area'][$areaCode])) { throw new ApiError(422, 'Choose a valid province or region.', ['province' => 'Unknown province/region.']); }
        $isRegion = false;
        foreach ($data['areas'] as $area) { if ($area['code'] === $areaCode) { $isRegion = $area['kind'] === 'region'; break; } }
        return array_map(static function (string $code) use ($data, $isRegion): array {
            $city = $data['cities'][$code];
            return ['code' => $code, 'name' => $city['name'], 'label' => $city['name'] . ($isRegion && $city['province_name'] ? ' — ' . $city['province_name'] : '')];
        }, $data['cities_by_area'][$areaCode]);
    }

    public function barangays(string $cityCode): array
    {
        $city = $this->data()['cities'][$cityCode] ?? null;
        if (!$city) { throw new ApiError(422, 'Choose a valid city or municipality.', ['city' => 'Unknown city/municipality.']); }
        return $city['barangays'];
    }

    public function resolve(string $areaCode, string $cityCode, string $barangayCode): array
    {
        $data = $this->data();
        $area = null;
        foreach ($data['areas'] as $candidate) { if ($candidate['code'] === $areaCode) { $area = $candidate; break; } }
        if (!$area) { throw new ApiError(422, 'Choose a valid province or region.', ['province' => 'Select a listed province/region.']); }
        $city = $data['cities'][$cityCode] ?? null;
        if (!$city || !in_array($areaCode, $city['area_codes'], true)) { throw new ApiError(422, 'The city does not belong to the selected province/region.', ['city' => 'Choose a city/municipality from this province/region.']); }
        $barangay = null;
        foreach ($city['barangays'] as $candidate) { if ($candidate['code'] === $barangayCode) { $barangay = $candidate; break; } }
        if (!$barangay) { throw new ApiError(422, 'The barangay does not belong to the selected city/municipality.', ['barangay' => 'Choose a barangay from this city/municipality.']); }
        $areaName = $area['name'];
        if ($area['kind'] === 'region' && $city['province_name']) { $areaName = $city['province_name'] . ', ' . $areaName; }
        return ['province' => $areaName, 'city' => $city['name'], 'barangay' => $barangay['name']];
    }
}
