<?php

namespace App\Http\Requests\Developer;

use App\Enums\Scope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'scopes' => ['required', 'array', 'list', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::enum(Scope::class)],
        ];
    }
}
