<?php

use App\Services\Security\PwnedPasswordVerifier;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Fase 6.33 (3): prueba de humo del transporte HTTP real (Guzzle y psr7),
 * sin Http::fake, contra dos servidores `php -S` locales. Sólo se permiten
 * peticiones a 127.0.0.1 y localhost: cualquier otra (Facturapi,
 * pwnedpasswords) sigue bloqueada por preventStrayRequests.
 */
function phase633LocalServer(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    $php = (new PhpExecutableFinder)->find(false) ?: 'php';
    $process = new Process([$php, '-S', "127.0.0.1:{$port}", base_path('tests/Fixtures/http-smoke-router.php')]);
    $process->start();

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($connection) {
            fclose($connection);

            return [$process, $port];
        }
        usleep(100_000);
    }

    $process->stop(0);
    throw new RuntimeException('El servidor local de la prueba de humo no arrancó.');
}

/** La misma forma que FacturapiProvider::request(), con una llave ficticia. */
function phase633Client(int $port)
{
    return Http::baseUrl("http://127.0.0.1:{$port}")
        ->withToken('sk_test_ficticia_prueba_de_humo')
        ->acceptJson()
        ->asJson()
        ->connectTimeout(3)
        ->timeout(3);
}

beforeEach(function () {
    [$this->server, $this->port] = phase633LocalServer();
    [$this->other, $this->otherPort] = phase633LocalServer();
    Http::allowStrayRequests(['http://127.0.0.1:*', 'http://localhost:*']);
});

afterEach(function () {
    $this->server->stop(0);
    $this->other->stop(0);
});

test('fuera de los servidores locales todo sigue bloqueado', function () {
    expect(fn () => Http::get('https://api.pwnedpasswords.com/range/00000'))
        ->toThrow(StrayRequestException::class);
});

test('el transporte real envía la credencial y recibe JSON', function () {
    $response = phase633Client($this->port)->get('/echo');

    expect($response->status())->toBe(200)
        ->and($response->json('host'))->toBe("127.0.0.1:{$this->port}")
        ->and($response->json('authorization'))->toBeTrue();
});

test('una redirección al mismo host conserva la credencial; a otro host la quita', function () {
    $sameHost = phase633Client($this->port)->get('/redirect', ['to' => "http://127.0.0.1:{$this->port}/echo"]);
    $otherHost = phase633Client($this->port)->get('/redirect', ['to' => "http://localhost:{$this->otherPort}/echo"]);

    expect($sameHost->json('authorization'))->toBeTrue()
        ->and($otherHost->json('host'))->toBe("localhost:{$this->otherPort}")
        ->and($otherHost->json('authorization'))->toBeFalse();
});

test('el tiempo de espera de 3 s se respeta', function () {
    $start = microtime(true);

    expect(fn () => phase633Client($this->otherPort)->get('/slow', ['s' => 6]))->toThrow(ConnectionException::class);

    expect(microtime(true) - $start)->toBeGreaterThan(2.5)->toBeLessThan(5.0);
});

/** Lleva las consultas del verificador (api.pwnedpasswords.com) al servidor local. */
function phase633RouteVerifierTo(int $port, ?string $path = null, ?string $query = null): void
{
    Http::globalRequestMiddleware(function (RequestInterface $request) use ($port, $path, $query) {
        $uri = $request->getUri()->withScheme('http')->withHost('127.0.0.1')->withPort($port);

        return $request->withUri($path === null ? $uri : $uri->withPath($path)->withQuery((string) $query));
    });
}

test('PwnedPasswordVerifier con transporte real: responde y no registra nada', function () {
    Log::spy();
    phase633RouteVerifierTo($this->port);

    expect(app(UncompromisedVerifier::class)->verify(['value' => 'Contraseña-Humo-2026!', 'threshold' => 0]))->toBeTrue();

    Log::shouldNotHaveReceived('warning');
});

test('PwnedPasswordVerifier con transporte real: falla abierto en 3 s si el servicio no responde', function () {
    Log::spy();
    phase633RouteVerifierTo($this->otherPort, '/slow', 's=6');
    $start = microtime(true);

    expect(app(UncompromisedVerifier::class)->verify(['value' => 'Contraseña-Humo-2026!', 'threshold' => 0]))->toBeTrue()
        ->and(microtime(true) - $start)->toBeGreaterThan(2.5)->toBeLessThan(5.0);

    Log::shouldHaveReceived('warning')->once()->with(PwnedPasswordVerifier::EVENT, Mockery::on(
        fn (array $context): bool => $context['reason'] === 'connection' && $context['timeout_seconds'] === 3
    ));
});
