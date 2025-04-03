<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use Illuminate\Http\Request;
use App\Services\KudaGo;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = KudaGo::getCategories();

        $cats = array_map(function ($category) {
            return (object) $category;
        }, $categories);
        
        return response()->json(['categories' => CategoryResource::collection($cats)]);
    }
}