<?php

namespace App\Http\Requests\Comments;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('comment'));
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'regex:/\S/u', 'max:'.(int) config('openbook.comments.max_length', 2000)],
        ];
    }
}
