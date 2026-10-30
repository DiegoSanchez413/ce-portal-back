<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Deja los archivos del cliente en un prefijo temporal de S3 para que el Soft los registre.
final class TempUploads
{
    /**
     * @param  list<UploadedFile>  $files
     * @return array{keys: list<string>, names: list<string>}
     */
    public function put(array $files): array
    {
        $prefix = 'client-portal/tmp/'.Str::uuid();
        $keys = [];
        $names = [];

        foreach ($files as $i => $file) {
            $extension = match ($file->getMimeType()) {
                'image/png' => 'png',
                'application/pdf' => 'pdf',
                default => 'jpg',
            };
            $keys[] = (string) Storage::disk('s3')->putFileAs($prefix, $file, ($i + 1).'.'.$extension);
            $names[] = mb_substr($file->getClientOriginalName(), 0, 150);
        }

        return ['keys' => $keys, 'names' => $names];
    }

    /** @param list<string> $keys */
    public function forget(array $keys): void
    {
        Storage::disk('s3')->delete($keys);
    }
}
