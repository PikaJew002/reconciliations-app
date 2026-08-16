<?php

namespace Tests\Unit\Support;

use App\Support\CategoryColor;
use PHPUnit\Framework\TestCase;

class CategoryColorTest extends TestCase
{
    public function test_from_hsl_red(): void
    {
        $this->assertSame('#FF0000', CategoryColor::fromHsl(0, 1, 0.5));
    }

    public function test_from_name_is_deterministic_hex(): void
    {
        $first = CategoryColor::fromName('Groceries');
        $second = CategoryColor::fromName('groceries');

        $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($first, CategoryColor::fromName('Electric'));
    }
}
