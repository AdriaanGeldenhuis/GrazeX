<?php
/**
 * Small server-side validator.
 *
 *   [$clean, $errors] = validate(input_all(), [
 *       'email'      => 'required|email|max:191',
 *       'first_name' => 'required|max:100',
 *       'qty'        => 'required|int|min:1|max:999',
 *       'method'     => 'required|in:eft,yoco,cash_on_collection',
 *   ]);
 *
 * Rules: required, nullable, string, email, phone, int, numeric, bool, min:n, max:n (length for strings,
 * value for numbers), in:a,b,c, date, time, regex:/.../, url, password, same:field, money.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

function validate(array $data, array $rules): array
{
    $clean = [];
    $errors = [];

    foreach ($rules as $field => $ruleString) {
        $ruleList = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
        $raw = $data[$field] ?? null;
        $value = is_string($raw) ? trim($raw) : $raw;
        $isNumeric = in_array('int', $ruleList, true) || in_array('numeric', $ruleList, true);
        $label = ucfirst(str_replace('_', ' ', $field));

        $empty = $value === null || $value === '' || (is_array($value) && !$value);
        if ($empty) {
            if (in_array('required', $ruleList, true)) {
                $errors[$field] = "{$label} is required.";
            } else {
                $clean[$field] = in_array('bool', $ruleList, true) ? false : null;
            }
            continue;
        }
        if (is_array($value) || is_object($value)) {
            // A list/object where text or a number is expected (e.g. name[]=x) — never let it reach string code
            $errors[$field] = "{$label} is invalid.";
            continue;
        }

        foreach ($ruleList as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $error = null;

            switch ($name) {
                case 'required':
                case 'nullable':
                    break;
                case 'string':
                    if (!is_string($value)) {
                        $error = "{$label} must be text.";
                    }
                    break;
                case 'email':
                    $value = mb_strtolower((string) $value);
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $error = 'Enter a valid email address.';
                    }
                    break;
                case 'phone':
                    $digits = preg_replace('/[^\d+]/', '', (string) $value) ?? '';
                    if (!preg_match('/^(\+?27|0)\d{9}$/', $digits)) {
                        $error = 'Enter a valid South African phone number.';
                    } else {
                        $value = $digits;
                    }
                    break;
                case 'int':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $error = "{$label} must be a whole number.";
                    } else {
                        $value = (int) $value;
                    }
                    break;
                case 'numeric':
                    if (!is_numeric($value)) {
                        $error = "{$label} must be a number.";
                    } else {
                        $value = (float) $value;
                    }
                    break;
                case 'money':
                    $cents = to_cents($value);
                    if ($cents === null || $cents < 0) {
                        $error = "{$label} must be a valid amount.";
                    } else {
                        $value = $cents;
                    }
                    break;
                case 'bool':
                    $value = in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
                    break;
                case 'min':
                    if ($isNumeric ? ((float) $value < (float) $param) : (mb_strlen((string) $value) < (int) $param)) {
                        $error = $isNumeric ? "{$label} must be at least {$param}." : "{$label} must be at least {$param} characters.";
                    }
                    break;
                case 'max':
                    if ($isNumeric ? ((float) $value > (float) $param) : (mb_strlen((string) $value) > (int) $param)) {
                        $error = $isNumeric ? "{$label} may not be more than {$param}." : "{$label} may not be longer than {$param} characters.";
                    }
                    break;
                case 'in':
                    $allowed = explode(',', (string) $param);
                    if (!in_array((string) $value, $allowed, true)) {
                        $error = "Choose a valid {$label}.";
                    }
                    break;
                case 'date':
                    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
                    if (!$d || $d->format('Y-m-d') !== $value) {
                        $error = "{$label} must be a valid date.";
                    }
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value)) {
                        $error = "{$label} must be a valid time.";
                    }
                    break;
                case 'regex':
                    if (!preg_match((string) $param, (string) $value)) {
                        $error = "{$label} has an invalid format.";
                    }
                    break;
                case 'url':
                    if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', (string) $value)) {
                        $error = "{$label} must be a valid web address.";
                    }
                    break;
                case 'password':
                    if (mb_strlen((string) $value) < GX_PASSWORD_MIN) {
                        $error = 'Password must be at least ' . GX_PASSWORD_MIN . ' characters.';
                    } elseif (mb_strlen((string) $value) > 200) {
                        $error = 'Password is too long.';
                    }
                    break;
                case 'same':
                    if ((string) $value !== (string) ($data[$param] ?? '')) {
                        $error = 'The values do not match.';
                    }
                    break;
            }

            if ($error !== null) {
                $errors[$field] = $error;
                break;
            }
        }

        if (!isset($errors[$field])) {
            if (is_string($value)) {
                // Strip control characters except newlines and tabs
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
            }
            $clean[$field] = $value;
        }
    }

    return [$clean, $errors];
}

/** Validate and stop with HTTP 422 on failure. Returns clean data. */
function validate_or_fail(array $data, array $rules): array
{
    [$clean, $errors] = validate($data, $rules);
    if ($errors) {
        json_error(t('error.validation'), 422, $errors);
    }
    return $clean;
}
