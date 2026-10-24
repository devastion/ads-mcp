<?php

namespace App\Http\Requests\V1;

use App\Actions\V1\SuggestNearest;
use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchAdsRequest extends FormRequest
{
    /** @var list<ApiErrorException> */
    private array $problems = [];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => [],
            'niche' => [],
            'format' => [],
            'status' => [],
            'started_after' => [],
            'sort' => [],
            'per_page' => [],
            'cursor' => [],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            $parameters = [];

            foreach (explode('&', $this->server->getString('QUERY_STRING')) as $pair) {
                if ($pair === '') {
                    continue;
                }

                [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
                $name = urldecode($rawName);
                $parameter = substr($name, 0, strcspn($name, '[]'));
                $parameters[$parameter] ??= ['name' => $parameter, 'values' => [], 'array' => false];
                $parameters[$parameter]['values'][] = urldecode($rawValue);
                $parameters[$parameter]['array'] = $parameters[$parameter]['array'] || $parameter !== $name;
            }

            foreach ($parameters as $parameter) {
                $name = $parameter['name'];
                $details = ['parameter' => $name];
                $code = ApiErrorCode::InvalidParameter;

                if (! in_array($name, $allowed, true)) {
                    $code = ApiErrorCode::UnknownParameter;
                    $message = 'Unknown query parameter "'.$name.'".';
                    $suggestion = app(SuggestNearest::class)->handle($name, $allowed);
                    $nextStep = 'Remove it. Allowed parameters: '.implode(', ', $allowed).'.';

                    if ($suggestion !== null) {
                        $details['did_you_mean'] = $suggestion;
                        $nextStep = 'Use "'.$suggestion.'" instead.';
                    }
                } elseif ($parameter['array']) {
                    $message = 'Query parameter "'.$name.'" does not accept array syntax.';
                    $nextStep = 'Send the parameter without array syntax.';
                } elseif (count($parameter['values']) > 1) {
                    $message = 'Query parameter "'.$name.'" must not be repeated.';
                    $nextStep = 'Send the parameter once.';
                } elseif (trim($parameter['values'][0]) === '') {
                    $message = 'Query parameter "'.$name.'" must not be empty.';
                    $nextStep = 'Omit the parameter instead of sending it empty.';
                } else {
                    continue;
                }

                $this->problems[] = new ApiErrorException($code, $message, $nextStep, $details);
                $validator->errors()->add($name, $message);
            }
        });
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->problems === []) {
            parent::failedValidation($validator);
        }

        $first = $this->problems[0];
        $details = $first->details();

        if (count($this->problems) > 1) {
            $details['errors'] = array_map(fn (ApiErrorException $problem): array => [
                'code' => $problem->code()->value,
                'message' => $problem->message(),
                'next_step' => $problem->nextStep(),
                'retryable' => $problem->code()->retryable(),
                'details' => $problem->details(),
            ], $this->problems);
        }

        throw new ApiErrorException($first->code(), $first->message(), $first->nextStep(), $details);
    }
}
