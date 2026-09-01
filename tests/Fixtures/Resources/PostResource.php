<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'published' => $this->published,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
