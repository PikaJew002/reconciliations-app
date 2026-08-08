<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounts\AccountBrowseService;
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
