<?php

namespace App\Policies;

use App\Models\PlaidItem;
use App\Models\User;

class PlaidItemPolicy
{
    public function view(User $user, PlaidItem $item): bool
    {
        return $user->id === $item->user_id && $user->plaid_enabled;
    }

    public function update(User $user, PlaidItem $item): bool
    {
        return $this->view($user, $item);
    }

    public function delete(User $user, PlaidItem $item): bool
    {
        return $this->view($user, $item);
    }
}
