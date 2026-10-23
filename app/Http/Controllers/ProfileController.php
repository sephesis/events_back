<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateRequest;
use App\Http\Resources\UserResource;
use App\Services\Recommendation\MarkovChainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => new UserResource($user),
            'stats' => [
                'itineraries' => $user->itineraries()->count(),
                'interactions' => $user->interactions()->count(),
            ],
        ]);
    }

    public function update(UpdateRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['user' => new UserResource($user->fresh())]);
    }

    /** Очистить историю действий — персональная модель вернётся к общей. */
    public function clearHistory(Request $request): JsonResponse
    {
        $request->user()->interactions()->delete();

        return response()->json(['message' => 'История действий очищена.']);
    }

    public function destroy(Request $request, MarkovChainService $markov): JsonResponse
    {
        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();
        $markov->forgetGlobal();

        return response()->json(['message' => 'Аккаунт удалён.']);
    }
}
