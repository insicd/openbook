<?php

namespace App\Http\Controllers;

use App\Application\Queries\InstanceStaffQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __construct(
        private readonly InstanceStaffQuery $instanceStaff,
    ) {}

    public function index(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('feed.index');
        }

        return view('home', [
            'staffMembers' => $this->instanceStaff->all(),
        ]);
    }
}
