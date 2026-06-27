<?php

namespace App\Http\Controllers\Promotion;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->input('query', '');

        $promotions = Promotion::where('is_active', true)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->where('name', 'ilike', '%' . $query . '%')
            ->select('id', 'name', 'type', 'value', 'start_date', 'end_date')
            ->orderBy('name')
            ->limit(10)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $promotions,
        ]);
    }
}
