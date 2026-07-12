<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCharacterRequest;
use App\Http\Requests\UpdateCharacterRequest;
use App\Http\Resources\CharacterResource;
use App\Services\CharacterLibrarian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CharacterController extends Controller
{
    /**
     * The saved-character library, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $characters = $request->user()->characters()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return CharacterResource::collection($characters);
    }

    /**
     * Create a reusable character, decoding an optional photo data URL.
     */
    public function store(StoreCharacterRequest $request, CharacterLibrarian $librarian): JsonResponse
    {
        $character = $librarian->create($request->user(), $request->validated());

        return CharacterResource::make($character)->response($request)->setStatusCode(201);
    }

    /**
     * Update a saved character with PATCH semantics.
     */
    public function update(UpdateCharacterRequest $request, CharacterLibrarian $librarian, int $id): CharacterResource
    {
        $character = $request->user()->characters()->findOrFail($id);

        return CharacterResource::make($librarian->update($character, $request->validated()));
    }

    /**
     * Owner-scoped delete; a foreign or missing id gets the same 204, so the
     * existence of foreign ids is not revealed.
     */
    public function destroy(Request $request, CharacterLibrarian $librarian, int $id): Response
    {
        $character = $request->user()->characters()->whereKey($id)->first();

        if ($character !== null) {
            $librarian->delete($character);
        }

        return response()->noContent();
    }
}
