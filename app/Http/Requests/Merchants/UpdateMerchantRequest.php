<?php

namespace App\Http\Requests\Merchants;

use App\Models\Merchant;
use App\Services\Merchants\MerchantBrowseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $merchant = $this->route('merchant');

        if ($this->user() === null || ! $merchant instanceof Merchant) {
            return false;
        }

        if (! app(MerchantBrowseService::class)->isBrowsable($this->user()->id, $merchant)) {
            throw new NotFoundHttpException();
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'normalized_name' => Str::of((string) $this->input('name'))->lower()->squish()->toString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $merchant = $this->route('merchant');
        $merchantId = $merchant instanceof Merchant ? $merchant->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('merchants', 'normalized_name')
                    ->where('user_id', $this->user()?->id)
                    ->ignore($merchantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'normalized_name.unique' => 'You already have a merchant with this name.',
        ];
    }
}
