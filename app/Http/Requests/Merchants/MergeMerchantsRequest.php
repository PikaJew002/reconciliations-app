<?php

namespace App\Http\Requests\Merchants;

use App\Models\Merchant;
use App\Services\Merchants\MerchantBrowseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MergeMerchantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $ids = collect($this->input('merchant_ids'))
            ->filter(fn ($id): bool => is_numeric($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->count() < 2) {
            return true;
        }

        $browse = app(MerchantBrowseService::class);

        /** @var Collection<int, Merchant> $merchants */
        $merchants = Merchant::query()->whereIn('id', $ids)->get();

        if ($merchants->count() !== $ids->count()) {
            throw new NotFoundHttpException;
        }

        foreach ($merchants as $merchant) {
            if (! $browse->isBrowsable($this->user()->id, $merchant)) {
                throw new NotFoundHttpException;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchant_ids' => ['required', 'array', 'min:2'],
            'merchant_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }
}
