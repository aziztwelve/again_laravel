<?php

namespace Tests\Unit;

use App\Http\Resources\OtoBanner\OtoBannerResource;
use App\Models\Image;
use App\Models\Oto\OtoBanner;
use Illuminate\Http\Request;
use Tests\TestCase;

class OtoBannerResourceTest extends TestCase
{
    public function test_it_uses_the_current_origin_for_an_oto_banner_image(): void
    {
        $banner = new class extends OtoBanner {
            public function getViewsCountAttribute(): int
            {
                return 0;
            }

            public function getSubmissionsCountAttribute(): int
            {
                return 0;
            }
        };
        $banner->setRawAttributes([
            'id' => 1,
            'name' => 'Скидка',
            'status' => 'active',
            'device_type' => 'desktop',
            'input_field_type' => 'email',
            'views_count' => 0,
            'submissions_count' => 0,
        ]);

        $image = new Image;
        $image->setRawAttributes([
            'id' => 109,
            'path' => '865878fb-0398-4495-b871-bbea9eada70c.png',
            'url' => 'https://againdev.ru/storage/oto-banners/1/865878fb-0398-4495-b871-bbea9eada70c.png',
        ]);
        $banner->setRelation('mainImage', $image);

        $data = (new OtoBannerResource($banner))->toArray(Request::create('/api/public/oto-banners/active'));

        self::assertSame('/storage/oto-banners/1/865878fb-0398-4495-b871-bbea9eada70c.png', $data['image']['url']);
    }
}
