<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'user' => $this->user,
            'users' => $this->users,
            'access_token' => 'access token',
            'expires_in' => 'expires in',
            'refresh_token' => 'refresh token',
            'refresh_expires_in' => 'refresh expires in',
        ];
    }
}
