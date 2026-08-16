<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\VenmoActivity;
use App\Services\Imports\Contracts\Importer;
use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VenmoActivityImporter implements Importer
{
    /**
     * @var list<string>
     */
    protected array $requiredHeaders = [
        'id',
        'datetime',
        'type',
        'amount (total)',
    ];

    public function import(ImportBatch $batch): int
    {
        $created = 0;

        foreach ($this->activityRows($batch->storage_path) as $row) {
            $attributes = $this->mapRow($row);

            if ($attributes === null) {
                continue;
            }

            $activity = VenmoActivity::query()->firstOrCreate(
                [
                    'user_id' => $batch->user_id,
                    'external_id' => $attributes['external_id'],
                ],
                [
                    ...$attributes,
                    'import_batch_id' => $batch->id,
                    'match_status' => VenmoActivity::STATUS_UNMATCHED,
                    'metadata' => $row,
                ],
            );

            if ($activity->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * @return Generator<int, array<string, string|null>>
     */
    protected function activityRows(string $storagePath): Generator
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
            $headers = null;

            while (($values = fgetcsv($handle)) !== false) {
                if ($values === [null] || $this->rowIsEmpty($values)) {
                    continue;
                }

                if ($headers === null) {
                    if (! $this->isHeaderRow($values)) {
                        continue;
                    }

                    $headers = $this->normalizeHeaders($values);
                    $this->assertRequiredHeaders($headers);

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
     * @param  array<string, string|null>  $row
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row): ?array
    {
        $externalId = trim((string) ($row['id'] ?? ''));
        $datetime = trim((string) ($row['datetime'] ?? ''));
        $type = trim((string) ($row['type'] ?? ''));
        $amount = $this->parseAmount($row['amount (total)'] ?? null);

        if ($externalId === '' || ! ctype_digit($externalId) || $datetime === '' || $type === '' || $amount === null) {
            return null;
        }

        $occurredAt = $this->parseDatetime($datetime);

        if ($occurredAt === null) {
            return null;
        }

        $fundingSource = $this->nullableString($row['funding source'] ?? null);
        $destination = $this->nullableString($row['destination'] ?? null);

        return [
            'external_id' => $externalId,
            'occurred_at' => $occurredAt,
            'type' => Str::of($type)->lower()->replace(' ', '_')->toString(),
            'status' => $this->nullableString($row['status'] ?? null),
            'note' => $this->nullableString($row['note'] ?? null),
            'from_name' => $this->nullableString($row['from'] ?? null),
            'to_name' => $this->nullableString($row['to'] ?? null),
            'amount' => $amount,
            'fee' => $this->parseAmount($row['amount (fee)'] ?? null),
            'funding_source' => $fundingSource,
            'destination' => $destination,
            'funding_last_four' => $this->extractLastFour($fundingSource),
            'destination_last_four' => $this->extractLastFour($destination),
        ];
    }

    /**
     * @param  array<int, string|null>  $values
     */
    protected function isHeaderRow(array $values): bool
    {
        $normalized = array_map(fn ($value) => Str::of((string) $value)->lower()->trim()->toString(), $values);

        return in_array('id', $normalized, true)
            && in_array('datetime', $normalized, true)
            && in_array('type', $normalized, true);
    }

    /**
     * @param  array<int, string|null>  $values
     * @return list<string>
     */
    protected function normalizeHeaders(array $values): array
    {
        $headers = [];

        foreach ($values as $index => $value) {
            $header = is_string($value) ? $value : '';
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            $header = Str::of($header)->lower()->trim()->toString();

            $headers[] = $header !== '' ? $header : "_col_{$index}";
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     */
    protected function assertRequiredHeaders(array $headers): void
    {
        foreach ($this->requiredHeaders as $required) {
            if (! in_array($required, $headers, true)) {
                throw new RuntimeException("Venmo statement is missing required column [{$required}].");
            }
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

    protected function parseAmount(?string $value): ?float
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $negative = str_contains($value, '-');
        $clean = preg_replace('/[^0-9.]/', '', $value);

        if ($clean === null || $clean === '') {
            return null;
        }

        $amount = (float) $clean;

        return $negative ? -$amount : $amount;
    }

    protected function parseDatetime(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    protected function extractLastFour(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/\*(\d{4})\s*$/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
