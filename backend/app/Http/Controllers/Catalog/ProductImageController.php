<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function upload(Request $request, Product $product): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ], [
            'image.max' => 'La imagen no debe superar los 2MB.',
            'image.mimes' => 'Solo se permiten imágenes JPEG y PNG.',
        ]);

        if ($product->image_url) {
            $oldPath = str_replace(Storage::disk('public')->url(''), '', $product->image_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('image')->store('products', 'public');
        $url = Storage::disk('public')->url($path);

        $product->update(['image_url' => $url]);

        return response()->json([
            'status' => 'success',
            'data' => ['image_url' => $url],
            'metadata' => ['message' => 'Imagen subida correctamente.'],
        ]);
    }

    public function destroy(Product $product): JsonResponse
    {
        if ($product->image_url) {
            $oldPath = str_replace(Storage::disk('public')->url(''), '', $product->image_url);
            Storage::disk('public')->delete($oldPath);
            $product->update(['image_url' => null]);
        }

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Imagen eliminada.'],
        ]);
    }
}
