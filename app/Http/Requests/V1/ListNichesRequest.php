<?php

namespace App\Http\Requests\V1;

class ListNichesRequest extends StrictQueryRequest
{
    protected function allowedParameters(): array
    {
        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
