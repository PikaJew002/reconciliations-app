<?php

namespace App\Services\Imports\Concerns;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

trait ReadsCsv
{
    /**
     * Yield associative rows from a CSV stored on the local disk.
     *
     * @return Generator<int, array<string, string|null>>
     */
    protected function rows(string $storagePath): Generator
    {
        $absolutePath = Storage::disk('local')->path($storagePath);

        if (! is_readable($absolutePath)) {
            throw new RuntimeException("Import file is not readable: {$storagePath}");
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open import file: {$storagePath}");
        }

        try {
            $headers = fgetcsv($handle);

            if ($headers === false || $headers === [null]) {
                return;
            }

            $headers = array_map(
                fn ($header) => is_string($header) ? trim($header) : $header,
                $headers,
            );

            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null] || $this->rowIsEmpty($values)) {
                    continue;
                }

                $padded = array_pad($values, count($headers), null);

                yield array_combine($headers, array_slice($padded, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string|null>  $values
     */
    protected function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
