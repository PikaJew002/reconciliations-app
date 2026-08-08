<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Models\Account;
use App\Services\Accounts\AccountBrowseService;
use App\Services\Imports\Banks\CapitalOneCreditCardTransactionImporter;
use App\Services\Imports\Banks\CumberlandValleyNationalBankTransactionImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AccountController extends Controller
{
    public function index(Request $request, AccountBrowseService $browse): Response
    {
        $data = $browse->index(
            $request->user()->id,
            $request->string('q')->toString() ?: null,
        );

        return Inertia::render('Accounts/Index', $data);
    }

    public function create(): Response
    {
        return Inertia::render('Accounts/Create', [
            'institutions' => [
                CapitalOneCreditCardTransactionImporter::INSTITUTION_NAME,
                CumberlandValleyNationalBankTransactionImporter::INSTITUTION_NAME,
            ],
            'accountTypes' => [
                Account::CHECKING,
                Account::SAVINGS,
                Account::CREDIT_CARD,
                Account::CASH,
            ],
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $account = Account::create([
            ...$request->validated(),
            'is_active' => true,
        ]);

        return redirect()
            ->route('imports.bank-transactions.create')
            ->with('success', "Account \"{$account->name}\" created. Import transactions to start using it.");
    }

    public function show(Request $request, Account $account, AccountBrowseService $browse): Response
    {
        $data = $browse->show(
            $request->user()->id,
            $account,
            $request->string('q')->toString() ?: null,
        );

        if ($data === null) {
            throw new NotFoundHttpException();
        }

        return Inertia::render('Accounts/Show', $data);
    }
}
