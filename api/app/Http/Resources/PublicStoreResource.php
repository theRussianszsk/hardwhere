<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/** What a customer may know about a store. No staff, no tenant settings. */
class PublicStoreResource extends JsonResource
{
    public function toArray($request): array
    {
        $dest = $this->lat !== null ? "{$this->lat},{$this->lng}" : urlencode("{$this->name}, {$this->address_line}, {$this->city}");

        return [
            'id' => $this->id, 'business_name' => $this->whenLoaded('tenant', fn () => $this->tenant->name), 'name' => $this->name,
            'address_line' => $this->address_line, 'barangay' => $this->barangay, 'city' => $this->city, 'province' => $this->province, 'postal_code' => $this->postal_code,
            'lat' => $this->lat, 'lng' => $this->lng, 'phone' => $this->phone, 'email' => $this->email, 'hours' => $this->hours ?: (object) [],
            'supports_pickup' => $this->supports_pickup, 'supports_delivery' => $this->supports_delivery,
            'directions_url' => "https://www.google.com/maps/dir/?api=1&destination=$dest", 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
