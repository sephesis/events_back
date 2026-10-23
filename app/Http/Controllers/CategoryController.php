<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Services\EventSourceService;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(EventSourceService $source): JsonResponse
    {
        $categories = array_map(fn (array $category) => (object) $category, $source->categories());

        return response()->json(['categories' => CategoryResource::collection($categories)]);
    }
}
