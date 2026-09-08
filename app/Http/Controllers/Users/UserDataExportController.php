<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Services\Users\ExportUserDataService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class UserDataExportController extends Controller
{
    public function show(string $token, ExportUserDataService $export): BinaryFileResponse|Response
    {
        $manifest = $export->manifest($token);

        if ($manifest === null) {
            abort(404);
        }

        if ($export->isExpired($manifest)) {
            abort(410, 'This export has expired. Run user:export-data again.');
        }

        if (auth()->id() !== ($manifest['user_id'] ?? null)) {
            abort(403);
        }

        $path = $export->exportSqlPath($token);

        if ($path === null) {
            abort(404);
        }

        return response()->download(
            $path,
            $manifest['download_filename'] ?? 'spendable-user-export.sql',
            ['Content-Type' => 'application/sql'],
        );
    }
}
