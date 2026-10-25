<?php

namespace App\Http\Requests\V1;

use App\Actions\V1\SuggestNearest;
use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Rejects query parameters the endpoint does not define and parameters sent more than once.
 */
abstract class StrictQueryRequest extends FormRequest
{
    /** @var array<string, ApiErrorException> */
    private array $problems = [];

    /**
     * @return list<string>
     */
    abstract protected function allowedParameters(): array;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->allowedParameters();
            $counts = [];

            foreach (explode('&', $this->server->getString('QUERY_STRING')) as $pair) {
                if ($pair !== '') {
                    $name = urldecode(explode('=', $pair, 2)[0]);
                    $counts[$name] = ($counts[$name] ?? 0) + 1;
                }
            }

            foreach ($counts as $name => $count) {
                $details = ['parameter' => (string) $name];

                if (! in_array((string) $name, $allowed, true)) {
                    $suggestion = app(SuggestNearest::class)->handle((string) $name, $allowed);
                    $nextStep = $allowed === []
                        ? 'Remove it. This endpoint takes no query parameters.'
                        : 'Remove it. Allowed parameters: '.implode(', ', $allowed).'.';

                    if ($suggestion !== null) {
                        $details['did_you_mean'] = $suggestion;
                        $nextStep = 'Use "'.$suggestion.'" instead.';
                    }

                    $problem = new ApiErrorException(ApiErrorCode::UnknownParameter, 'Unknown query parameter "'.$name.'".', $nextStep, $details);
                } elseif ($count > 1) {
                    $problem = new ApiErrorException(ApiErrorCode::InvalidParameter, 'Query parameter "'.$name.'" must not be repeated.', 'Send the parameter once.', $details);
                } else {
                    continue;
                }

                $this->problems[(string) $name] = $problem;
                $validator->errors()->add((string) $name, $problem->message());
            }
        });
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        $key = (string) $validator->errors()->keys()[0];

        throw $this->problems[$key] ?? new ApiErrorException(
            ApiErrorCode::InvalidParameter,
            $validator->errors()->first(),
            'Send a value the parameter accepts.',
            ['parameter' => $key],
        );
    }
}
