<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Category extends Model
{
    use HasFactory;

    public const KIND_BILL = 'bill';

    public const KIND_EXPENSE = 'expense';

    protected $fillable = [
        'user_id',
        'parent_id',
        'kind',
        'name',
        'slug',
        'color',
        'icon',
        'sort_order',
        'is_active',
        'is_system',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orderComponents(): HasMany
    {
        return $this->hasMany(OrderComponent::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function categorizationRules(): HasMany
    {
        return $this->hasMany(TransactionCategorizationRule::class);
    }

    public function isBill(): bool
    {
        return $this->kind === self::KIND_BILL;
    }

    public function isExpense(): bool
    {
        return $this->kind === self::KIND_EXPENSE;
    }

    public function isInUse(): bool
    {
        return $this->products()->exists()
            || $this->orderComponents()->exists()
            || $this->bankTransactions()->exists()
            || $this->categorizationRules()->exists();
    }

    public static function uniqueSlugFor(int $userId, string $kind, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 2;

        while (
            self::query()
                ->where('user_id', $userId)
                ->where('kind', $kind)
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationRules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['required', Rule::in([self::KIND_BILL, self::KIND_EXPENSE])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
