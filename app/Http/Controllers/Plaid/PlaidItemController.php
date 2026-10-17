<?php

namespace App\Http\Controllers\Plaid;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plaid\StorePlaidItemRequest;
use App\Http\Requests\Plaid\UpdatePlaidAccountsRequest;
use App\Jobs\SyncPlaidTransactions;
use App\Models\PlaidItem;
use App\Services\Plaid\PlaidAccountMapping;
use App\Services\Plaid\PlaidLinkService;
use App\Services\Plaid\PlaidRequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlaidItemController extends Controller
{
    public function store(StorePlaidItemRequest $request, PlaidLinkService $links): RedirectResponse
    {
        try {
            $item = $links->exchange(
                $request->user(),
                $request->string('public_token')->toString(),
                $request->input('institution_id'),
                $request->input('link_session_id'),
            );
        } catch (PlaidRequestException $exception) {
            return redirect()
                ->route('accounts.index')
                ->with('error', $exception->userMessage());
        }

        return redirect()
            ->route('plaid.items.accounts', $item)
            ->with('success', 'Bank connected. Choose which accounts to import.');
    }

    public function accounts(Request $request, PlaidItem $item, PlaidAccountMapping $mapping): Response
    {
        $this->authorize('view', $item);

        return Inertia::render('Plaid/Map', $mapping->page($item, $request->user()));
    }

    public function updateAccounts(UpdatePlaidAccountsRequest $request, PlaidItem $item, PlaidAccountMapping $mapping): RedirectResponse
    {
        $this->authorize('update', $item);

        $mapping->save($item, $request->user(), $request->validated('mappings'));

        return redirect()
            ->route('accounts.index')
            ->with('success', 'Account mapping saved. New transactions will import on the next sync.');
    }

    public function sync(PlaidItem $item): RedirectResponse
    {
        $this->authorize('update', $item);

        SyncPlaidTransactions::dispatch($item->id);

        return redirect()
            ->route('accounts.index')
            ->with('success', 'Sync queued.');
    }

    public function repaired(Request $request, PlaidItem $item, PlaidLinkService $links): RedirectResponse
    {
        $this->authorize('update', $item);

        $links->markRepaired($item, $request->input('link_session_id'));

        SyncPlaidTransactions::dispatch($item->id);

        return redirect()
            ->route('accounts.index')
            ->with('success', 'Bank reconnected.');
    }

    public function destroy(PlaidItem $item, PlaidLinkService $links): RedirectResponse
    {
        $this->authorize('delete', $item);

        try {
            $links->remove($item);
        } catch (PlaidRequestException $exception) {
            return redirect()
                ->route('accounts.index')
                ->with('error', $exception->userMessage());
        }

        return redirect()
            ->route('accounts.index')
            ->with('success', 'Bank connection removed. Imported transactions were kept.');
    }
}
