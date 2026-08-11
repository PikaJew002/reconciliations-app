<?php

namespace App\Http\Controllers\Categories;

use App\Http\Controllers\Controller;
use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $kind = $request->string('kind')->toString();

        if (! in_array($kind, [...Category::kinds(), ''], true)) {
            $kind = '';
        }

        $categories = Category::query()
            ->where('user_id', $request->user()->id)
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->orderBy('kind')
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
                'slug' => $category->slug,
                'is_in_use' => $category->isInUse(),
            ]);

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
            'filters' => [
                'kind' => $kind !== '' ? $kind : null,
            ],
            'kinds' => Category::kinds(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Categories/Create', [
            'kinds' => Category::kinds(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $category = Category::query()->create([
            'user_id' => $request->user()->id,
            'kind' => $validated['kind'],
            'name' => $validated['name'],
            'slug' => Category::uniqueSlugFor(
                $request->user()->id,
                $validated['kind'],
                $validated['name'],
            ),
            'color' => $validated['color'] ?? null,
            'is_active' => true,
            'is_system' => false,
            'sort_order' => 0,
        ]);

        return redirect()
            ->route('categories.index', ['kind' => $category->kind])
            ->with('success', "Category \"{$category->name}\" created.");
    }

    public function edit(Request $request, Category $category): Response
    {
        $this->ensureOwned($request, $category);

        return Inertia::render('Categories/Edit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'kind' => $category->kind,
                'color' => $category->color,
            ],
            'kinds' => Category::kinds(),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->update([
            'kind' => $validated['kind'],
            'name' => $validated['name'],
            'slug' => Category::uniqueSlugFor(
                $request->user()->id,
                $validated['kind'],
                $validated['name'],
                $category->id,
            ),
            'color' => $validated['color'] ?? null,
        ]);

        return redirect()
            ->route('categories.index', ['kind' => $category->kind])
            ->with('success', "Category \"{$category->name}\" updated.");
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->ensureOwned($request, $category);

        if ($category->isInUse()) {
            return redirect()
                ->route('categories.index', ['kind' => $category->kind])
                ->with('error', "Category \"{$category->name}\" is in use and cannot be deleted.");
        }

        $kind = $category->kind;
        $name = $category->name;
        $category->delete();

        return redirect()
            ->route('categories.index', ['kind' => $kind])
            ->with('success', "Category \"{$name}\" deleted.");
    }

    private function ensureOwned(Request $request, Category $category): void
    {
        if ($category->user_id !== $request->user()->id) {
            throw new NotFoundHttpException();
        }
    }
}
