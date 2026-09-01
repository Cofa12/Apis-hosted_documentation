<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Controllers;

use Cofa\ApiDocs\Attributes\ApiHeader;
use Cofa\ApiDocs\Attributes\ApiResponse;
use Cofa\ApiDocs\Tests\Fixtures\Models\User;
use Cofa\ApiDocs\Tests\Fixtures\Requests\StoreUserRequest;
use Cofa\ApiDocs\Tests\Fixtures\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Users
 *
 * Everything related to user accounts.
 */
class UserController
{
    /**
     * List users
     *
     * Returns a paginated list of every user in the account.
     *
     * @queryParam status string Filter by account status. Example: active
     */
    public function index(Request $request)
    {
        $search = $request->query('search');
        $sort = $request->input('sort', 'created_at');
        $onlyAdmins = $request->boolean('admins');

        return UserResource::collection(User::query()->paginate(25));
    }

    /**
     * Create a user
     *
     * @authenticated
     */
    #[ApiHeader(name: 'X-Tenant', value: 'acme', required: true, description: 'The tenant to create the user in.')]
    public function store(StoreUserRequest $request): JsonResponse
    {
        return response()->json(['data' => ['id' => 1, 'name' => 'Ada Lovelace']], 201);
    }

    /**
     * Get a user
     */
    public function show(User $user): UserResource
    {
        return new UserResource($user);
    }

    /**
     * Update a user
     */
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'sometimes|string|max:60',
            'email' => 'sometimes|email',
        ]);

        return new UserResource($user);
    }

    /**
     * Delete a user
     */
    #[ApiResponse(status: 204, description: 'The user was removed.')]
    public function destroy(User $user)
    {
        abort_if($user->is_admin, 403, 'Admins cannot be deleted.');

        return response()->noContent();
    }

    /**
     * @ignore
     */
    public function internal(): JsonResponse
    {
        return response()->json([]);
    }
}
