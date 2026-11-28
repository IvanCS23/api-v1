<?php

namespace App\Enums;

enum SocialProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    /**
     * Sin credenciales en el .env el proveedor responde 503 en lugar de
     * mandar al usuario a una pantalla de error del proveedor.
     */
    public function isConfigured(): bool
    {
        return filled(config("services.{$this->value}.client_id"))
            && filled(config("services.{$this->value}.client_secret"))
            && filled(config("services.{$this->value}.redirect"));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $provider): string => $provider->value, self::cases());
    }
}
