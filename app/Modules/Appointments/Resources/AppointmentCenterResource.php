<?php

namespace App\Modules\Appointments\Resources;

use App\Models\AppointmentCenter;
use App\Modules\Appointments\Support\AppointmentCenterMapUrlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AppointmentCenter */
class AppointmentCenterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latitude = $this->latitude !== null ? (float) $this->latitude : null;
        $longitude = $this->longitude !== null ? (float) $this->longitude : null;
        $urls = AppointmentCenterMapUrlBuilder::urls($latitude, $longitude, $this->address);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'map_url' => $urls['map_url'],
            'directions_url' => $urls['directions_url'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromSlotLocation(?string $location): ?array
    {
        $trimmed = trim((string) $location);
        if ($trimmed === '') {
            return null;
        }

        $urls = AppointmentCenterMapUrlBuilder::urls(null, null, $trimmed);

        return [
            'id' => null,
            'name' => $trimmed,
            'address' => $trimmed,
            'latitude' => null,
            'longitude' => null,
            'map_url' => $urls['map_url'],
            'directions_url' => $urls['directions_url'],
        ];
    }
}
