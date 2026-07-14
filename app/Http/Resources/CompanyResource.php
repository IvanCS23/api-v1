<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->trade_name,
            'rfc' => $this->rfc,
            'plan' => $this->plan,
            'integration_status' => $this->integration_status,
        ];
    }
}
