<?php

declare(strict_types=1);

namespace App\Modules\ClientLogs;

use Illuminate\Foundation\Http\FormRequest;

final class StoreClientLogRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'level' => ['required', 'in:error,warning'],
            'message' => ['required', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
