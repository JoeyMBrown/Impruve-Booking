<?php

namespace App\Http\Controllers;

use App\Http\Requests\SelectActingUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Placeholder "pick who you are" auth. Uses the standard web session guard
 * with no password, so real auth can replace it later.
 */
class WhoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('who/index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(SelectActingUserRequest $request): RedirectResponse
    {
        Auth::login(User::findOrFail($request->integer('user_id')));

        $request->session()->regenerate();

        return redirect()->route('calendar');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Inertia::flash('toast', ['type' => 'info', 'message' => 'Signed out. Pick who you are to continue.']);

        return redirect()->route('who.index');
    }
}
