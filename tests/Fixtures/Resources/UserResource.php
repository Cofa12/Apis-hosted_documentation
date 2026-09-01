<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'is_admin' => $this->is_admin,
            'profile' => [
                'avatar' => $this->avatar,
                'city' => $this->city,
            ],
            'posts' => PostResource::collection($this->whenLoaded('posts')),
            'created_at' => $this->created_at,
        ];
    }
}
