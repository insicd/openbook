<?php

namespace App\Http\Controllers\Admin;

use App\Application\Services\DomainBlockManager;
use App\Domain\Moderation\DomainBlock;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DomainBlockController extends Controller
{
    public function __construct(
        private readonly DomainBlockManager $domainBlockManager,
    ) {}

    public function index(): View
    {
        return view('admin.domain-blocks.index', [
            'blocks' => DomainBlock::query()
                ->with('creator')
                ->orderBy('domain')
                ->paginate(40),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [], [
            'domain' => __('openbook.admin.domain_blocks.domain'),
            'reason' => __('openbook.admin.domain_blocks.reason'),
        ]);

        try {
            $this->domainBlockManager->block($request->user(), $data['domain'], $data['reason'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.domain_blocks.blocked'));
    }

    public function destroy(DomainBlock $domainBlock): RedirectResponse
    {
        try {
            $this->domainBlockManager->unblock(auth()->user(), $domainBlock);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['domain' => $exception->getMessage()]);
        }

        return back()->with('status', __('openbook.admin.domain_blocks.unblocked'));
    }
}
