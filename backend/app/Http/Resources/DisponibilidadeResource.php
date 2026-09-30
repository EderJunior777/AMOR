<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Horarios de inicio livres de um dia. Recebe ['data' => 'AAAA-MM-DD',
 * 'horarios' => ['HH:MM', ...]]: nada de nome, id ou motivo de bloqueio.
 */
class DisponibilidadeResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->resource['data'],
            'horarios' => array_values($this->resource['horarios']),
        ];
    }
}
