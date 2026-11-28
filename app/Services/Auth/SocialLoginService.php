<?php

namespace App\Services\Auth;

use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\SocialLoginException;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Reglas del login social (Google y Microsoft):
 *
 * - Solo entran usuarios que ya existen: el administrador los da de alta.
 *   El login social nunca crea usuarios.
 * - Solo el rol Client puede entrar por esta vía; los demás roles siguen
 *   con correo y contraseña.
 * - El correo debe venir verificado por el proveedor. En Microsoft, una
 *   cuenta de empresa necesita la notificación `xms_edov` (el dominio del
 *   correo es de esa empresa); sin ella se rechaza.
 * - La API no pone el token en la URL: el callback entrega un código de un
 *   solo uso (60 s) que el frontend cambia por el token en un POST.
 */
final class SocialLoginService
{
    private const STATE_TTL_SECONDS = 600;

    private const CODE_TTL_SECONDS = 60;

    /** Inquilino fijo de las cuentas personales de Microsoft (Outlook, Hotmail). */
    private const MICROSOFT_CONSUMER_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /**
     * `state` de OAuth: protege el callback contra CSRF. Las rutas de API no
     * tienen sesión, así que se guarda en caché y se usa una sola vez.
     */
    public function createState(SocialProvider $provider): string
    {
        $state = Str::random(40);

        Cache::put($this->stateKey($state), $provider->value, self::STATE_TTL_SECONDS);

        return $state;
    }

    public function consumeState(SocialProvider $provider, mixed $state): bool
    {
        if (! is_string($state) || $state === '') {
            return false;
        }

        return Cache::pull($this->stateKey($state)) === $provider->value;
    }

    /**
     * @throws SocialLoginException
     */
    public function resolveUser(SocialProvider $provider, SocialiteUser $socialUser): User
    {
        $providerUserId = (string) $socialUser->getId();

        if ($providerUserId === '') {
            throw new SocialLoginException('provider_error');
        }

        $email = $this->verifiedEmail($provider, $socialUser);

        $linked = SocialAccount::query()
            ->where('provider', $provider->value)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($linked !== null) {
            $user = $linked->user;
        } else {
            if ($email === null) {
                throw new SocialLoginException('email_not_verified');
            }

            $user = $this->findUserByEmail($email);
        }

        $this->assertCanLogin($user);

        SocialAccount::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => $provider->value],
            ['provider_user_id' => $providerUserId, 'email' => $email, 'last_used_at' => now()],
        );

        return $user;
    }

    public function createLoginCode(User $user): string
    {
        $code = Str::random(64);

        Cache::put($this->codeKey($code), $user->getKey(), self::CODE_TTL_SECONDS);

        return $code;
    }

    /**
     * Cambia el código por el usuario. El código se borra al leerlo, así que
     * un segundo intento con el mismo código falla.
     *
     * @throws SocialLoginException
     */
    public function consumeLoginCode(string $code): User
    {
        $userId = Cache::pull($this->codeKey($code));

        if ($userId === null) {
            throw new SocialLoginException('invalid_code');
        }

        $user = User::query()->find($userId);

        // Se vuelve a revisar: el usuario pudo desactivarse o cambiar de rol
        // entre el callback y el canje.
        $this->assertCanLogin($user);

        return $user;
    }

    /**
     * @throws SocialLoginException
     */
    public function assertCanLogin(?User $user): void
    {
        if ($user === null) {
            throw new SocialLoginException('account_not_found');
        }

        if ($user->role !== UserRole::Client) {
            throw new SocialLoginException('role_not_allowed');
        }

        if ($user->status !== UserStatus::Active) {
            throw new SocialLoginException('account_inactive');
        }
    }

    /**
     * Correo del proveedor solo si el proveedor garantiza que pertenece al
     * usuario. Devuelve null en cualquier otro caso.
     */
    public function verifiedEmail(SocialProvider $provider, SocialiteUser $socialUser): ?string
    {
        return match ($provider) {
            SocialProvider::Google => $this->googleVerifiedEmail($socialUser),
            SocialProvider::Microsoft => $this->microsoftVerifiedEmail($socialUser),
        };
    }

    private function googleVerifiedEmail(SocialiteUser $socialUser): ?string
    {
        $raw = method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : [];
        $verified = $raw['email_verified'] ?? false;

        if (! $this->isTrue($verified)) {
            return null;
        }

        return $this->normalizeEmail($socialUser->getEmail());
    }

    /**
     * Microsoft no garantiza el correo de una cuenta de empresa: el
     * administrador de cualquier inquilino puede escribir uno ajeno. Por eso:
     * - Cuenta personal (inquilino de consumidores): el correo es válido.
     * - Cuenta de empresa: solo si el id_token trae `xms_edov` verdadero.
     */
    private function microsoftVerifiedEmail(SocialiteUser $socialUser): ?string
    {
        $claims = $this->idTokenClaims($socialUser);

        if ($claims === null) {
            return null;
        }

        $email = $this->normalizeEmail($claims['email'] ?? null);

        if ($email === null) {
            return null;
        }

        $isPersonalAccount = ($claims['tid'] ?? null) === self::MICROSOFT_CONSUMER_TENANT;

        if ($isPersonalAccount || $this->isTrue($claims['xms_edov'] ?? null)) {
            return $email;
        }

        return null;
    }

    /**
     * Notificaciones del id_token. No se valida la firma porque el token
     * llegó directo del endpoint de tokens de Microsoft, servidor a servidor
     * por TLS, en el canje del código (OpenID Connect Core, 3.1.3.7).
     *
     * @return array<string, mixed>|null
     */
    private function idTokenClaims(SocialiteUser $socialUser): ?array
    {
        $body = property_exists($socialUser, 'accessTokenResponseBody')
            ? (array) $socialUser->accessTokenResponseBody
            : [];

        $idToken = $body['id_token'] ?? null;

        if (! is_string($idToken)) {
            return null;
        }

        $parts = explode('.', $idToken);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $payload === false ? null : json_decode($payload, true);

        return is_array($claims) ? $claims : null;
    }

    /**
     * @throws SocialLoginException
     */
    private function findUserByEmail(string $email): User
    {
        $matches = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->limit(2)
            ->get();

        // Cero o más de uno: no se adivina a qué cuenta entrar.
        if ($matches->count() !== 1) {
            throw new SocialLoginException('account_not_found');
        }

        return $matches->first();
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email));
    }

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true'], true);
    }

    private function stateKey(string $state): string
    {
        return 'social-login:state:'.hash('sha256', $state);
    }

    private function codeKey(string $code): string
    {
        return 'social-login:code:'.hash('sha256', $code);
    }
}
