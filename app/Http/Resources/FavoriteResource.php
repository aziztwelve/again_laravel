<?php

namespace App\Http\Resources;

use App\Models\Discount;
use App\Traits\HelperTrait;
use App\Traits\ProductsTrait;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    use ProductsTrait;
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->product)
            $this->applyDiscountToProduct($this->product, Discount::CUSTOMER_TYPE_AUTHORIZED);


        if ($this->productVariant)
            $this->applyDiscountToProduct($this->productVariant, Discount::CUSTOMER_TYPE_AUTHORIZED);

        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'product' => $this->product ? new ProductNumberThreeResource($this->product) : null,
            'product_variant' => $this->productVariant ? new ProductVariantNumberTwoResource($this->productVariant) : null,
            'created_at' => $this->created_at,
        ];
    }
}
