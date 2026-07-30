<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaLibraryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => MediaFile::latest()->paginate($request->integer('per_page', 48))]);
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
        $data = $request->validate(['media_ids' => ['required', 'array'], 'media_ids.*' => ['integer', 'exists:media_files,id'], 'variant_id' => ['nullable', 'integer', 'exists:product_variants,id']]);
        $target = isset($data['variant_id']) ? ProductVariant::findOrFail($data['variant_id']) : $product;
        if ($target instanceof ProductVariant && $target->product_id !== $product->id) abort(422);
        $relation = $target->morphToMany(MediaFile::class, 'media_fileable', 'media_fileables')->withPivot(['position', 'is_main']);
        $start = $relation->count();
        foreach ($data['media_ids'] as $offset => $id) $relation->syncWithoutDetaching([$id => ['position' => $start + $offset + 1, 'is_main' => $start === 0 && $offset === 0]]);
        return response()->json(['data' => $relation->orderByPivot('position')->get()]);
    }
}
