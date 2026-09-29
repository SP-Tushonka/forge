<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ModSubscription;
use App\Models\User;
use Illuminate\Http\Response;

final class ModSubscriptionController extends Controller
{
    /**
     * Stop telling the user about new versions of the given mod.
     */
    public function unsubscribe(User $user, int $modId): Response
    {
        ModSubscription::query()->where('user_id', $user->id)->where('mod_id', $modId)->delete();

        return response()->view('static.mod-unsubscribed');
    }
}
