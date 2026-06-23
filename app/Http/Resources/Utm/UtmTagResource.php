<?php

namespace App\Http\Resources\Utm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UtmTagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
