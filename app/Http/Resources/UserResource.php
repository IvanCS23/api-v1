<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar ? Storage::disk('public')->url($this->avatar) : null,
            'role' => $this->role,
            'status' => $this->status,
            'phone' => $this->phone,
            'position' => $this->position,
            'branch' => $this->branch,
            'must_change_password' => $this->must_change_password,
            'password_changed_at' => $this->password_changed_at,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'company' => $this->whenLoaded(
                'company',
                fn () => $this->company ? new CompanyResource($this->company) : null,
            ),
        ];
    }
}
