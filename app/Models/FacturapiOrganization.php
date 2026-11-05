<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Fase 6.27: organización de Facturapi de una empresa (1:1).
 *
 * `test_secret_key` va cifrada en reposo (cast `encrypted`, APP_KEY), no
 * es fillable y está en `$hidden`: nunca llega a un Resource, a un log ni
 * a una respuesta. Sólo se asigna con setTestSecretKey(), que también
 * guarda `test_key_hint`, lo único que se muestra de ella.
 *
 * Usa BelongsToCompany: fuera del tenant de su empresa no se encuentra, así
 * que una resolución con el tenant equivocado falla cerrada
 * (pac_organization_missing) en lugar de usar otra llave.
 */
class FacturapiOrganization extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'organization_id',
        'legal_name',
        'legal_trade_name',
        'legal_tax_id',
        'legal_tax_system',
        'legal_zip',
        'has_certificate',
        'certificate_serial_number',
        'certificate_expires_at',
        'certificate_updated_at',
        'has_logo',
        'is_production_ready',
        'pending_steps',
        'snapshot_at',
    ];

    /** Fase 6.31: mismo default que la columna, para instancias sin recargar. */
    protected $attributes = [
        'pac_environment' => 'test',
    ];

    protected $hidden = [
        'test_secret_key',
        'live_secret_key',
    ];

    protected $casts = [
        'test_secret_key' => 'encrypted',
        'test_key_set_at' => 'immutable_datetime',
        // Fase 6.31: llave Live, mismo trato que la Test.
        'live_secret_key' => 'encrypted',
        'live_key_set_at' => 'immutable_datetime',
        'has_certificate' => 'boolean',
        'certificate_expires_at' => 'immutable_datetime',
        'certificate_updated_at' => 'immutable_datetime',
        'has_logo' => 'boolean',
        'is_production_ready' => 'boolean',
        'pending_steps' => 'array',
        'snapshot_at' => 'immutable_datetime',
    ];

    public function setTestSecretKey(#[SensitiveParameter] ?string $key): static
    {
        return $this->forceFill([
            'test_secret_key' => $key,
            'test_key_hint' => $key === null ? null : self::hint($key),
            'test_key_set_at' => $key === null ? null : now(),
        ]);
    }

    /**
     * Fase 6.31: sólo la usa `billing:facturapi-live-key`, después de
     * comprobar con GET /organizations/me que la llave es de esta
     * organización. No es fillable ni sale en ninguna serialización.
     */
    public function setLiveSecretKey(#[SensitiveParameter] ?string $key): static
    {
        return $this->forceFill([
            'live_secret_key' => $key,
            'live_key_hint' => $key === null ? null : self::hint($key),
            'live_key_set_at' => $key === null ? null : now(),
        ]);
    }

    /** `sk_test_…a1b2`: prefijo y últimos 4 caracteres, nunca más. */
    public static function hint(#[SensitiveParameter] string $key): string
    {
        $prefix = preg_match('/^(sk_[a-z]+_)/', $key, $matches) === 1 ? $matches[1] : '';
        $tail = mb_strlen($key) - mb_strlen($prefix) >= 12 ? mb_substr($key, -4) : '';

        return $prefix.'…'.$tail;
    }
}
