<?php

namespace App\Http\Controllers\Merchants;

use App\Http\Controllers\Controller;
use App\Http\Requests\Merchants\UpdateMerchantRequest;
use App\Models\Merchant;
use App\Services\Merchants\MerchantBrowseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantController extends Controller
{
    public function show(Request $request, Merchant $merchant, MerchantBrowseService $browse): Response
    {
        $data = $browse->show(
            $request->user()->id,
            $merchant,
            $request->string('q')->toString() ?: null,
        );

        if ($data === null) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('Merchants/Show', $data);
    }

    public function update(UpdateMerchantRequest $request, Merchant $merchant): RedirectResponse
    {
        $validated = $request->validated();

        $merchant->update([
            'name' => $validated['name'],
            'normalized_name' => $validated['normalized_name'],
        ]);

        return redirect()
            ->route('merchants.show', $merchant)
            ->with('success', 'Merchant name updated.');
    }
}
