<?php

namespace App\Policies;

use App\Models\BookingProposal;
use App\Models\User;

/**
 * Only the user a proposal was made for can confirm or dismiss it
 * (FR-CHAT-14); the SDK doesn't check this, so we do.
 */
class BookingProposalPolicy
{
    public function view(User $user, BookingProposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    public function confirm(User $user, BookingProposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }

    public function dismiss(User $user, BookingProposal $proposal): bool
    {
        return $user->id === $proposal->user_id;
    }
}
