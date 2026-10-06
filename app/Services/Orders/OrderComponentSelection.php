<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderComponent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class OrderComponentSelection
{
    /**
     * Components named by the request, always including the route component.
     * Omitting component_ids keeps the historical single-component behavior.
     *
     * @return Collection<int, OrderComponent>
     */
    public function resolve(Request $request, Order $order, OrderComponent $component): Collection
    {
        $raw = $request->exists('component_ids')
            ? $request->input('component_ids')
            : [$component->id];

        if (! is_array($raw) || $raw === []) {
            abort(422, 'Choose at least one component.');
        }

        $ids = collect($raw)
            ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->unique()
            ->values();

        abort_unless($ids->every(fn (int $id): bool => $id > 0), 422, 'Choose at least one component.');
        abort_unless($ids->contains($component->id), 422, 'Choose at least one component.');

        $components = OrderComponent::query()
            ->where('order_id', $order->id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        abort_unless($components->count() === $ids->count(), 404);

        return $components;
    }
}
