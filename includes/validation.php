<?php

declare(strict_types=1);

function validate_required(string $value, string $label): ?string
{
    if (trim($value) === '') {
        return $label . ' is required.';
    }

    return null;
}

function validate_email(string $email): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Enter a valid email address.';
    }

    return null;
}

function validate_phone(string $phone): ?string
{
    $normalized = preg_replace('/\s+/', '', $phone) ?? $phone;
    if (!preg_match('/^(\+254|0)(7|1)\d{8}$/', $normalized)) {
        return 'Enter a valid Kenyan phone number, for example 0712 345 678.';
    }

    return null;
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\s+/', '', $phone) ?? $phone;
}

function validate_password(string $password): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }

    return null;
}

function validate_password_confirmation(string $password, string $confirm): ?string
{
    if ($password !== $confirm) {
        return 'Password confirmation does not match.';
    }

    return null;
}

function first_error(array $errors): array
{
    return array_values(array_filter($errors));
}
