<?php

namespace App\Actions\Mcp;

use App\Actions\V1\SuggestNearest;
use App\DTO\McpToolError;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\ApiErrorCode;
use App\Enums\Niche;

/**
 * The single argument check shared by the request classifier and the tools. A call that fails it is charged locally.
 */
class ValidateToolArguments
{
    /**
     * Argument rules per tool. `type` is the JSON type; bounds and enumerations are checked as the contract states.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rules(string $tool): array
    {
        return match ($tool) {
            'get_ad' => [
                'id' => ['type' => 'integer', 'min' => 1, 'required' => true],
                'body_offset' => ['type' => 'integer', 'min' => 0],
                'body_limit' => ['type' => 'integer', 'min' => 1, 'max' => 1000],
            ],
            'search_ads' => [
                'q' => ['type' => 'string', 'trim' => true, 'min' => 2, 'max' => 200],
                'niche' => ['type' => 'list', 'minItems' => 1, 'values' => array_column(Niche::cases(), 'value')],
                'format' => ['type' => 'enum', 'values' => array_column(AdFormat::cases(), 'value')],
                'status' => ['type' => 'enum', 'values' => array_column(AdStatus::cases(), 'value')],
                'started_after' => ['type' => 'date'],
                'sort' => ['type' => 'enum', 'values' => ['newest', 'impressions']],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 10],
                'cursor' => ['type' => 'string', 'min' => 1, 'max' => 2048],
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>|null  $arguments  the call's full argument object, for corrected calls
     */
    public function handle(string $tool, mixed $arguments): ?McpToolError
    {
        if (! is_array($arguments)) {
            return new McpToolError(ApiErrorCode::InvalidParameter, 'Arguments must be a JSON object.', 'Send the arguments as an object, or omit them.');
        }

        $rules = $this->rules($tool);
        $allowed = array_keys($rules);

        foreach (array_keys($arguments) as $name) {
            if (! in_array((string) $name, $allowed, true)) {
                $suggestion = app(SuggestNearest::class)->handle((string) $name, $allowed);
                $details = ['parameter' => (string) $name];
                $nextStep = $allowed === []
                    ? 'Remove the argument. This tool takes no arguments.'
                    : 'Remove it. Allowed arguments: '.implode(', ', $allowed).'.';

                if ($suggestion !== null) {
                    $details['did_you_mean'] = $suggestion;
                    $nextStep = 'Use "'.$suggestion.'" instead.';
                }

                return new McpToolError(ApiErrorCode::UnknownParameter, 'Unknown argument "'.$name.'".', $nextStep, $details);
            }
        }

        foreach ($rules as $name => $rule) {
            if (! array_key_exists($name, $arguments)) {
                if ($rule['required'] ?? false) {
                    return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" is required.', 'Add "'.$name.'" to the arguments.', ['parameter' => $name]);
                }

                continue;
            }

            $fault = $this->checkValue($tool, $name, $rule, $arguments);

            if ($fault !== null) {
                return $fault;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $arguments
     */
    private function checkValue(string $tool, string $name, array $rule, array $arguments): ?McpToolError
    {
        $value = $arguments[$name];
        $details = ['parameter' => $name];

        return match ($rule['type']) {
            'integer' => $this->checkInteger($name, $rule, $value),
            'string' => $this->checkString($name, $rule, $value),
            'date' => $this->checkDate($name, $value),
            'enum' => $this->checkEnum($tool, $name, $rule['values'], $value, $arguments),
            'list' => $this->checkList($tool, $name, $rule, $value, $arguments),
            default => new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" is not valid.', 'Send a value the argument accepts.', $details),
        };
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function checkInteger(string $name, array $rule, mixed $value): ?McpToolError
    {
        if (! is_int($value)) {
            return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" must be a whole number.', 'Send "'.$name.'" as a JSON integer without quotes.', ['parameter' => $name]);
        }

        if (isset($rule['min']) && $value < $rule['min'] || isset($rule['max']) && $value > $rule['max']) {
            $range = isset($rule['max']) ? $rule['min'].' to '.$rule['max'] : 'at least '.$rule['min'];

            return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" is out of range.', 'Use a value from '.$range.'. Out-of-range values are rejected, not clamped.', ['parameter' => $name, 'min' => $rule['min'], 'max' => $rule['max'] ?? null]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function checkString(string $name, array $rule, mixed $value): ?McpToolError
    {
        if (! is_string($value)) {
            return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" must be a string.', 'Send "'.$name.'" as a JSON string.', ['parameter' => $name]);
        }

        $length = mb_strlen(($rule['trim'] ?? false) ? trim($value) : $value);

        if ($length < ($rule['min'] ?? 0) || $length > ($rule['max'] ?? PHP_INT_MAX)) {
            return new McpToolError(
                ApiErrorCode::InvalidParameter,
                'Argument "'.$name.'" must be '.$rule['min'].' to '.$rule['max'].' characters after trimming.',
                'Correct the length of "'.$name.'" or omit it.',
                ['parameter' => $name, 'min' => $rule['min'], 'max' => $rule['max']],
            );
        }

        return null;
    }

    private function checkDate(string $name, mixed $value): ?McpToolError
    {
        if (is_string($value) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" must be a real date in YYYY-MM-DD form.', 'Send a real calendar date such as 2026-09-30.', ['parameter' => $name]);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $arguments
     */
    private function checkEnum(string $tool, string $name, array $values, mixed $value, array $arguments): ?McpToolError
    {
        if (is_string($value) && in_array($value, $values, true)) {
            return null;
        }

        $corrected = is_string($value) ? $this->suggestion($value, $values) : null;

        return $this->enumError($tool, $name, $values, $corrected, $arguments, [$name => $corrected]);
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $arguments
     */
    private function checkList(string $tool, string $name, array $rule, mixed $value, array $arguments): ?McpToolError
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < ($rule['minItems'] ?? 0)) {
            return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" must be a non-empty array of slugs.', 'Send an array such as ["skincare"], with one or more exact slugs from list_niches.', ['parameter' => $name]);
        }

        $corrected = [];
        $allCorrectable = true;
        $firstFault = null;

        foreach ($value as $element) {
            if (is_string($element) && in_array($element, $rule['values'], true)) {
                $corrected[] = $element;

                continue;
            }

            $suggestion = is_string($element) ? $this->suggestion($element, $rule['values']) : null;
            $firstFault ??= $element;

            if ($suggestion === null) {
                $allCorrectable = false;
            } else {
                $corrected[] = $suggestion;
            }
        }

        if ($firstFault === null) {
            return null;
        }

        return $this->enumError($tool, $name, $rule['values'], $allCorrectable ? $corrected : null, $arguments, [$name => $allCorrectable ? $corrected : null]);
    }

    /**
     * @param  list<string>  $values
     */
    private function suggestion(string $value, array $values): ?string
    {
        $trimmed = trim($value);

        if (in_array($trimmed, $values, true)) {
            return $trimmed;
        }

        return app(SuggestNearest::class)->handle($trimmed, $values);
    }

    /**
     * @param  list<string>  $values
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $replacement
     */
    private function enumError(string $tool, string $name, array $values, mixed $corrected, array $arguments, array $replacement): McpToolError
    {
        $details = ['parameter' => $name];
        $nextStep = 'Send one of: '.implode(', ', $values).'.';

        if (is_array($corrected) && $corrected !== [] && $corrected !== [null]) {
            $callArguments = [...$arguments, ...array_filter($replacement, fn (mixed $v): bool => $v !== null)];
            $details['corrected_call'] = ['name' => $tool, 'arguments' => $callArguments];
            $nextStep = 'Use the corrected call: the exact slugs from list_niches.';
        } elseif (is_string($corrected)) {
            $details['did_you_mean'] = $corrected;
            $details['corrected_call'] = ['name' => $tool, 'arguments' => [...$arguments, $name => $corrected]];
            $nextStep = 'Use "'.$corrected.'" instead.';
        }

        return new McpToolError(ApiErrorCode::InvalidParameter, 'Argument "'.$name.'" must be one of the allowed values.', $nextStep, $details);
    }
}
