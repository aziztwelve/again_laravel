<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Traits\ImageTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaLibraryController extends Controller
{
    use ImageTrait;

    public function index(Request $request)
    {
        return response()->json(['data' => MediaFile::latest()->paginate($request->integer('per_page', 48))]);
    }

    public function productIndex(Request $request, Product $product)
    {
        $product->load([
            'images' => fn ($query) => $query->orderBy('order'),
            'variants' => fn ($query) => $query
                ->whereNull('deleted_at')
                ->with(['images' => fn ($imageQuery) => $imageQuery->orderBy('order')]),
        ]);

        $files = collect();

        foreach ($product->images as $image) {
            $files->push($this->formatImage($image, 'product', $product->id, $product->name));
        }

        foreach ($product->variants as $variant) {
            foreach ($variant->images as $image) {
                $files->push($this->formatImage($image, 'variant', $variant->id, $variant->name));
            }
        }

        return response()->json([
            'data' => $files
                ->unique(fn ($file) => $file['path'])
                ->values(),
        ]);
    }

    public function upload(Request $request)
    {
        $request->validate(['files' => ['required', 'array'], 'files.*' => ['image', 'max:32768']]);
        $files = collect($request->file('files'))->map(function ($file) {
            $path = $file->store('media-library', 'public');
            return MediaFile::create(['path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
        });
        return response()->json(['data' => $files], 201);
    }

    public function attach(Request $request, Product $product)
    {
        $data = $request->validate([
            'image_ids' => ['nullable', 'array'],
            'image_ids.*' => ['integer', 'exists:images,id'],
            'media_ids' => ['nullable', 'array'],
            'media_ids.*' => ['integer', 'exists:media_files,id'],
            'files' => ['nullable', 'array'],
            'files.*' => ['image', 'max:32768'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ]);

        $target = isset($data['variant_id']) ? ProductVariant::findOrFail($data['variant_id']) : $product;
        if ($target instanceof ProductVariant && $target->product_id !== $product->id) {
            abort(422, 'Variant does not belong to product.');
        }

        if ($request->hasFile('files')) {
            $uploaded = collect();
            $startPosition = $target->images()->count();

            foreach ($request->file('files') as $offset => $file) {
                $uploaded->push($this->save_images(
                    $file,
                    $target::class,
                    $target->id,
                    $startPosition + $offset
                ));
            }

            return response()->json([
                'data' => $target->images()->orderBy('order')->get(),
                'attached' => $uploaded->values(),
            ], 201);
        }

        if (!empty($data['image_ids'])) {
            $sourceImages = $this->productImages($product)
                ->whereIn('id', $data['image_ids'])
                ->get();

            if ($sourceImages->count() !== count(array_unique($data['image_ids']))) {
                abort(422, 'Some images do not belong to this product.');
            }

            $attached = collect();

            foreach ($sourceImages as $sourceImage) {
                $existingImage = $target->images()
                    ->where('path', $sourceImage->path)
                    ->first();

                if ($existingImage) {
                    $attached->push($existingImage);
                    continue;
                }

                $attached->push($target->images()->create([
                    'path' => $sourceImage->path,
                    'url' => $sourceImage->url ?: Storage::disk('public')->url($sourceImage->path),
                    'order' => $target->images()->count() + 1,
                    'is_main' => !$target->images()->exists(),
                    'blur_hash' => $sourceImage->blur_hash,
                ]));
            }

            return response()->json([
                'data' => $target->images()->orderBy('order')->get(),
                'attached' => $attached->values(),
            ]);
        }

        if (empty($data['media_ids'])) {
            abort(422, 'No images selected.');
        }

        $relation = $target->morphToMany(MediaFile::class, 'media_fileable', 'media_fileables')->withPivot(['position', 'is_main']);
        $start = $relation->count();
        foreach ($data['media_ids'] as $offset => $id) $relation->syncWithoutDetaching([$id => ['position' => $start + $offset + 1, 'is_main' => $start === 0 && $offset === 0]]);
        return response()->json(['data' => $relation->orderByPivot('position')->get()]);
    }

    private function formatImage(Image $image, string $sourceType, int $sourceId, ?string $sourceName): array
    {
        return [
            'id' => $image->id,
            'path' => $image->path,
            'url' => $image->url ?: Storage::disk('public')->url($image->path),
            'order' => $image->order,
            'position' => $image->order,
            'is_main' => (bool) $image->is_main,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_name' => $sourceName,
        ];
    }

    private function productImages(Product $product)
    {
        $variantIds = $product->variants()
            ->whereNull('deleted_at')
            ->pluck('id');

        return Image::query()
            ->where(function ($query) use ($product, $variantIds) {
                $query->where(function ($productQuery) use ($product) {
                    $productQuery
                        ->where('item_type', Product::class)
                        ->where('item_id', $product->id);
                })->orWhere(function ($variantQuery) use ($variantIds) {
                    $variantQuery
                        ->where('item_type', ProductVariant::class)
                        ->whereIn('item_id', $variantIds);
                });
            });
    }
}
