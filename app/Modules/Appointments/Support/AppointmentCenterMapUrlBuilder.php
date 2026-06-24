<?php

namespace App\Modules\Appointments\Support;

class AppointmentCenterMapUrlBuilder
{
    /**
     * @return array{map_url: ?string, directions_url: ?string}
     */
    public static function urls(?float $latitude, ?float $longitude, ?string $address = null): array
    {
        $query = self::resolveQuery($latitude, $longitude, $address);

        if ($query === null) {
            return [
                'map_url' => null,
                'directions_url' => null,
            ];
        }

        return [
            'map_url' => 'https://www.google.com/maps/search/?api=1&query='.$query,
            'directions_url' => 'https://www.google.com/maps/dir/?api=1&destination='.$query,
        ];
    }

    private static function resolveQuery(?float $latitude, ?float $longitude, ?string $address): ?string
    {
        if ($latitude !== null && $longitude !== null) {
            return $latitude.','.$longitude;
        }

        $trimmedAddress = trim((string) $address);
        if ($trimmedAddress !== '') {
            return rawurlencode($trimmedAddress);
        }

        return null;
    }
}
