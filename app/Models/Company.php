<?php

namespace App\Models;

use App\Enums\CompanyCurrency;
use App\Enums\CompanyFiscalProvider;
use App\Enums\CompanyIntegrationStatus;
use App\Enums\CompanyLanguage;
use App\Enums\CompanyMode;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTheme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'legal_name',
        'trade_name',
        'rfc',
        'plan',
        'fiscal_regime',
        'email',
        'phone',
        'website',
        'logo',
        'status',
        'country',
        'state',
        'city',
        'municipality',
        'postal_code',
        'colony',
        'street',
        'external_number',
        'internal_number',
        'language',
        'currency',
        'timezone',
        'date_format',
        'time_format',
        'decimal_precision',
        'primary_color',
        'secondary_color',
        'theme',
        'mode',
        'default_cfdi_series',
        'default_cfdi_usage',
        'default_payment_form',
        'default_payment_method',
        'expedition_place',
        'fiscal_provider',
        'sandbox',
        'api_key',
        'integration_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'language' => CompanyLanguage::class,
            'currency' => CompanyCurrency::class,
            'theme' => CompanyTheme::class,
            'mode' => CompanyMode::class,
            'fiscal_provider' => CompanyFiscalProvider::class,
            'integration_status' => CompanyIntegrationStatus::class,
            'sandbox' => 'bool',
            'decimal_precision' => 'int',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $company): void {
            if ($company->uuid === null || $company->uuid === '') {
                $company->uuid = (string) Str::uuid();
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
