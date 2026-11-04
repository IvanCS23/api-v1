<?php

namespace Tests\Feature\Billing;

use App\Console\Commands\FacturapiLinkOrganization;
use App\Infrastructure\Billing\Facturapi\FacturapiProvider;
use App\Infrastructure\Billing\Facturapi\FacturapiProviderResolver;
use App\Models\FacturapiOrganization;
use App\Support\Billing\FacturapiUserKey;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacSecretRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use Tests\TestCase;

/**
 * Fase 6.31 (1): todo parámetro que recibe una llave de Facturapi lleva
 * #[SensitiveParameter]. Con `zend.exception_ignore_args` apagado, las
 * trazas de laravel.log incluyen los argumentos de cada llamada.
 */
class PacSecretParametersTest extends TestCase
{
    /** @return array<string, array{class-string, string, string}> */
    public static function keyParameters(): array
    {
        return [
            'provider' => [FacturapiProvider::class, '__construct', 'apiKey'],
            'resolver forKey' => [FacturapiProviderResolver::class, 'forKey', 'testKey'],
            'guard' => [PacCredentialGuard::class, '__construct', 'apiKey'],
            'user key' => [FacturapiUserKey::class, '__construct', 'key'],
            'organization setTestSecretKey' => [FacturapiOrganization::class, 'setTestSecretKey', 'key'],
            'organization hint' => [FacturapiOrganization::class, 'hint', 'key'],
            'link command' => [FacturapiLinkOrganization::class, 'link', 'key'],
        ];
    }

    /** @param  class-string  $class */
    #[DataProvider('keyParameters')]
    public function test_key_parameter_is_marked_sensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new ReflectionMethod($class, $method);
        $found = null;

        foreach ($reflection->getParameters() as $candidate) {
            if ($candidate->getName() === $parameter) {
                $found = $candidate;
            }
        }

        $this->assertNotNull($found, "{$class}::{$method}() no tiene el parámetro \${$parameter}.");
        $this->assertNotEmpty($found->getAttributes(SensitiveParameter::class), "{$class}::{$method}(\${$parameter}) debe llevar #[SensitiveParameter].");
    }

    public function test_trace_does_not_carry_the_key(): void
    {
        $key = 'sk_test_'.str_repeat('a', 40);

        try {
            (static function (#[SensitiveParameter] string $secret): never {
                throw new RuntimeException('falla');
            })($key);
        } catch (RuntimeException $e) {
            $args = $e->getTrace()[0]['args'] ?? [];
        }

        $this->assertStringNotContainsString($key, print_r($args ?? [], true));

        if (($args ?? []) !== []) {
            $this->assertInstanceOf(SensitiveParameterValue::class, $args[0]);
        }
    }

    public function test_redactor_covers_every_key_prefix(): void
    {
        foreach (['sk_test_', 'sk_live_', 'sk_user_'] as $prefix) {
            $this->assertSame('llave [redacted] fin', PacSecretRedactor::redact("llave {$prefix}AbC123._-xyz fin"));
        }
    }
}
