<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBlockedDateRequest;
use App\Models\BlockedDate;
use Illuminate\Http\RedirectResponse;

class BlockedDateController extends Controller
{
    /**
     * Block a date so no one can book it.
     */
    public function store(StoreBlockedDateRequest $request): RedirectResponse
    {
        BlockedDate::create($request->validated());

        return back();
    }

    /**
     * Unblock a date.
     */
    public function destroy(BlockedDate $blockedDate): RedirectResponse
    {
        $blockedDate->delete();

        return back();
    }
}
