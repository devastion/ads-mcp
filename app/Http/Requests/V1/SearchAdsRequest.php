<?php

namespace App\Http\Requests\V1;

use App\Actions\V1\SuggestNearest;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\ApiErrorCode;
use App\Enums\Niche;
use App\Http\Exceptions\V1\ApiErrorException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SearchAdsRequest extends FormRequest
{
    /** @var list<ApiErrorException> */
    private array $problems = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $niche = $this->rawParameters()['niche'] ?? null;

        if ($niche !== null && ! $niche['array']) {
            $this->merge(['niche' => $niche['values']]);
        }
    }

    /**
     * PHP keeps only the last value of a repeated key, so the raw query string is the source of truth.
     *
     * @return array<string, array{name: string, values: list<string>, array: bool}>
     */
    private function rawParameters(): array
    {
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

        return $parameters;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['string', 'min:2', 'max:200'],
            'niche' => ['array', Rule::in(array_column(Niche::cases(), 'value'))],
            'format' => [Rule::in(array_column(AdFormat::cases(), 'value'))],
            'status' => [Rule::in(array_column(AdStatus::cases(), 'value'))],
            'started_after' => ['date_format:Y-m-d'],
            'sort' => [Rule::in(['newest', 'impressions'])],
            'per_page' => ['integer', 'between:1,100'],
            'cursor' => ['string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.min' => 'Query parameter "q" must be at least 2 characters.',
            'q.max' => 'Query parameter "q" must be at most 200 characters.',
            'started_after.date_format' => 'Query parameter "started_after" must be a real date in YYYY-MM-DD form.',
            'per_page.integer' => 'Query parameter "per_page" must be a whole number from 1 to 100.',
            'per_page.between' => 'Query parameter "per_page" must be between 1 and 100.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = array_keys($this->rules());

            foreach ($this->rawParameters() as $parameter) {
                $name = $parameter['name'];
                $details = ['parameter' => $name];
                $code = ApiErrorCode::InvalidParameter;

                if ($name === 'niche' && ! $parameter['array']) {
                    $problem = $this->nicheProblem($parameter['values']);

                    if ($problem !== null) {
                        $this->problems[] = $problem;
                        $validator->errors()->add($name, $problem->message());
                    }

                    continue;
                }

                if (in_array($name, ['format', 'status', 'sort'], true) && ! $parameter['array'] && count($parameter['values']) === 1 && trim($parameter['values'][0]) !== '') {
                    $values = match ($name) {
                        'format' => array_column(AdFormat::cases(), 'value'),
                        'status' => array_column(AdStatus::cases(), 'value'),
                        default => ['newest', 'impressions'],
                    };
                    $problem = $this->enumProblem($name, $values, $parameter['values'][0]);

                    if ($problem !== null) {
                        $this->problems[] = $problem;
                        $validator->errors()->add($name, $problem->message());
                    }

                    continue;
                }

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

    /**
     * @param  list<string>  $values
     */
    private function nicheProblem(array $values): ?ApiErrorException
    {
        $slugs = array_column(Niche::cases(), 'value');

        foreach ($values as $value) {
            if (str_contains($value, ',')) {
                $split = array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $slug): bool => $slug !== ''));
                $repeated = implode('&', array_map(fn (string $slug): string => 'niche='.rawurlencode($slug), $split));

                return new ApiErrorException(
                    ApiErrorCode::InvalidParameter,
                    'Query parameter "niche" must not be comma-separated.',
                    'Repeat the parameter instead: '.$repeated.'.',
                    ['parameter' => 'niche'],
                );
            }

            if (trim($value) === '') {
                return new ApiErrorException(
                    ApiErrorCode::InvalidParameter,
                    'Query parameter "niche" must not be empty.',
                    'Omit the parameter instead of sending it empty.',
                    ['parameter' => 'niche'],
                );
            }

            if (! in_array($value, $slugs, true)) {
                $details = ['parameter' => 'niche'];
                $suggestion = app(SuggestNearest::class)->handle($value, $slugs);
                $nextStep = 'Send one of: '.implode(', ', $slugs).'.';

                if ($suggestion !== null) {
                    $details['did_you_mean'] = $suggestion;
                    $nextStep = 'Use "'.$suggestion.'" instead.';
                }

                return new ApiErrorException(
                    ApiErrorCode::InvalidParameter,
                    'Query parameter "niche" must be one of the known niche slugs.',
                    $nextStep,
                    $details,
                );
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $values
     */
    private function enumProblem(string $name, array $values, string $value): ?ApiErrorException
    {
        if (in_array($value, $values, true)) {
            return null;
        }

        $details = ['parameter' => $name];
        $suggestion = app(SuggestNearest::class)->handle($value, $values);
        $nextStep = 'Send one of: '.implode(', ', $values).'.';

        if ($suggestion !== null) {
            $details['did_you_mean'] = $suggestion;
            $nextStep = 'Use "'.$suggestion.'" instead.';
        }

        return new ApiErrorException(
            ApiErrorCode::InvalidParameter,
            'Query parameter "'.$name.'" must be one of the known '.match ($name) {
                'format' => 'formats',
                'status' => 'statuses',
                default => 'sort orders',
            }.'.',
            $nextStep,
            $details,
        );
    }

    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->problems === []) {
            $key = $validator->errors()->keys()[0];

            $this->problems[] = new ApiErrorException(
                ApiErrorCode::InvalidParameter,
                $validator->errors()->first(),
                'Send a value the parameter accepts.',
                ['parameter' => $key],
            );
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
