<?php

namespace App\Http\Requests\V1;

class ShowAdRequest extends StrictQueryRequest
{
    protected function allowedParameters(): array
    {
        return ['body_offset', 'body_limit'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['id' => $this->route('id')]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['integer', 'min:1'],
            'body_offset' => ['integer', 'min:0'],
            'body_limit' => ['integer', 'between:1,1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id.integer' => 'The ad id must be a positive whole number.',
            'id.min' => 'The ad id must be a positive whole number.',
            'body_offset.integer' => 'Query parameter "body_offset" must be a whole number of 0 or more.',
            'body_offset.min' => 'Query parameter "body_offset" must be a whole number of 0 or more.',
            'body_limit.integer' => 'Query parameter "body_limit" must be a whole number from 1 to 1000.',
            'body_limit.between' => 'Query parameter "body_limit" must be between 1 and 1000.',
        ];
    }
}
