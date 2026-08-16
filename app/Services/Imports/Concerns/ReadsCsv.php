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
            $firstLine = fgets($handle);

            if ($firstLine === false) {
                return;
            }

            $delimiter = $this->detectDelimiter($firstLine);
            rewind($handle);

            $headers = fgetcsv($handle, null, $delimiter);

            if ($headers === false || $headers === [null]) {
                return;
            }

            $headers = array_map(function ($header) {
                if (! is_string($header)) {
                    return $header;
                }

                // Strip UTF-8 BOM that some CSV exporters prepend to the first header.
                $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

                return trim($header);
            }, $headers);

            while (($values = fgetcsv($handle, null, $delimiter)) !== false) {
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
     * Detect comma vs tab from the header line so CSV and bank TXT exports both parse.
     */
    protected function detectDelimiter(string $line): string
    {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

        $tabs = substr_count($line, "\t");
        $commas = substr_count($line, ',');

        return $tabs > $commas ? "\t" : ',';
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
