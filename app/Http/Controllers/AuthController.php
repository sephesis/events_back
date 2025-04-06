<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {    
        return response()->json(['message' => 'Hello World']);
    }

    public function logout(): JsonResponse
    {
        return response()->json(['message' => 'Hello World']);
    }
}
