<?php

namespace App\Http\Requests\Categories;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $this->user() !== null
            && $category instanceof Category
            && $category->user_id === $this->user()->id;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'color' => $this->filled('color') ? $this->input('color') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');

        return (new Category)->validationRules(
            $category instanceof Category ? $category->id : null,
        );
    }
}
