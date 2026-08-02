<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ImportBatch $importBatch): bool
    {
        return $user->id === $importBatch->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
