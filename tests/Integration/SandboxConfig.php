<?php

namespace NetSuiteRest\Tests\Integration;

use NetSuiteRest\Config\NetSuiteConfig;
use RuntimeException;

/**
 * Builds a NetSuiteConfig for integration tests from the environment, loading a local .env
 * file for convenience if present. This translation lives here, in the test layer — the
 * library's NetSuiteConfig itself must stay free of getenv()/.env knowledge.
 *
 * On any real CI system, the expectation is that these environment variables are supplied
 * natively (the CI's own secret store), and no .env file is present at all.
 */
final class SandboxConfig
{
    private const REQUIRED_VARS = [
        'NETSUITE_CLIENT_ID',
        'NETSUITE_CERTIFICATE_ID',
        'NETSUITE_PRIVATE_KEY_PATH',
        'NETSUITE_REST_API_BASE_URL',
        'NETSUITE_SCOPES',
    ];

    /**
     * @throws RuntimeException if any required value is missing, or the private key file can't be read.
     */
    public static function fromEnvironment(): NetSuiteConfig
    {
        self::loadDotEnvOnce();

        $values = [];
        foreach (self::REQUIRED_VARS as $name) {
            $value = getenv($name);
            if ($value === false || $value === '') {
                throw new RuntimeException(
                    "Sandbox integration test skipped: environment variable {$name} is not set. "
                    . 'Copy .env.example to .env and fill in real sandbox values to run this test.',
                );
            }
            $values[$name] = $value;
        }

        $privateKeyPath = $values['NETSUITE_PRIVATE_KEY_PATH'];
        $privateKey = is_file($privateKeyPath) ? file_get_contents($privateKeyPath) : false;
        if ($privateKey === false) {
            throw new RuntimeException(
                "Sandbox integration test skipped: NETSUITE_PRIVATE_KEY_PATH ({$privateKeyPath}) "
                . 'does not point to a readable file.',
            );
        }

        return new NetSuiteConfig(
            clientId: $values['NETSUITE_CLIENT_ID'],
            certificateId: $values['NETSUITE_CERTIFICATE_ID'],
            privateKey: $privateKey,
            restApiBaseUrl: $values['NETSUITE_REST_API_BASE_URL'],
            scopes: array_map('trim', explode(',', $values['NETSUITE_SCOPES'])),
        );
    }

    private static function loadDotEnvOnce(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $path = dirname(__DIR__, 2) . '/.env';
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            $isQuoted = strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")));
            if ($isQuoted) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
            }
        }
    }
}
