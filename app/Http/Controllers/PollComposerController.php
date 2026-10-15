<?php

namespace App\Http\Controllers;

use App\Domain\Polls\LocalPollDefinition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Progressive form controls; publishing still uses StorePostRequest/PostComposer. */
final class PollComposerController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'poll.options' => ['required', 'array', 'list', 'max:'.LocalPollDefinition::MAX_OPTIONS],
            'poll.options.*' => ['nullable', 'string', 'max:200'],
            'poll_option_action' => ['required', 'regex:/\\A(?:add|clear|remove:[0-5])\\z/'],
        ]);
        $options = $data['poll']['options'];
        $action = $data['poll_option_action'];
        if ($action === 'clear') {
            $options = ['', ''];
        } elseif ($action === 'add' && count($options) < LocalPollDefinition::MAX_OPTIONS) {
            $options[] = '';
        } elseif (str_starts_with($action, 'remove:') && count($options) > LocalPollDefinition::MIN_OPTIONS) {
            unset($options[(int) substr($action, 7)]);
        }
        $input = $request->except(['_token', '_method', 'images', 'poll_option_action']);
        $input['poll']['options'] = array_values($options);
        if ($action === 'clear') {
            $input['poll']['multiple'] = false;
            $input['poll']['duration_seconds'] = LocalPollDefinition::DEFAULT_DURATION;
        }
        $input['poll_expanded'] = $action !== 'clear';

        return back()->withInput($input)->withFragment('composer-poll');
    }
}
