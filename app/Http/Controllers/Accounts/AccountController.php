<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Services\Accounts\AccountBrowseService;
use App\Services\Institutions\InstitutionRegistry;
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

    public function create(InstitutionRegistry $institutions): Response
    {
        return Inertia::render('Accounts/Create', [
            'institutions' => $institutions->names(),
            'accountTypes' => [
                Account::CHECKING,
                Account::SAVINGS,
                Account::CREDIT_CARD,
                Account::CASH,
            ],
            'defaultClassifications' => [
                BankTransaction::CLASSIFICATION_EXPENSE,
                BankTransaction::CLASSIFICATION_BILL,
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

    public function edit(Account $account, InstitutionRegistry $institutions): Response
    {
        return Inertia::render('Accounts/Edit', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'institution_name' => $account->institution_name,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
                'default_classification' => $account->default_classification,
                'currency' => $account->currency,
                'last_four' => $account->last_four,
            ],
            'institutions' => $institutions->names(),
            'accountTypes' => [
                Account::CHECKING,
                Account::SAVINGS,
                Account::CREDIT_CARD,
                Account::CASH,
            ],
            'defaultClassifications' => [
                BankTransaction::CLASSIFICATION_EXPENSE,
                BankTransaction::CLASSIFICATION_BILL,
            ],
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->validated());

        return redirect()
            ->route('accounts.edit', $account)
            ->with('success', "Account \"{$account->name}\" updated.");
    }
}
