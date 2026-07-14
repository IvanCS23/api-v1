<?php

use App\Enums\CompanyCurrency;
use App\Enums\CompanyFiscalProvider;
use App\Enums\CompanyIntegrationStatus;
use App\Enums\CompanyLanguage;
use App\Enums\CompanyMode;
use App\Enums\CompanyStatus;
use App\Enums\CompanyTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('legal_name');
            $table->string('trade_name');
            $table->string('rfc', 13)->unique();
            $table->string('fiscal_regime', 3);
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->string('status')->default(CompanyStatus::Active->value);
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('municipality')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('colony')->nullable();
            $table->string('street')->nullable();
            $table->string('external_number')->nullable();
            $table->string('internal_number')->nullable();
            $table->string('language', 5)->default(CompanyLanguage::Spanish->value);
            $table->string('currency', 10)->default(CompanyCurrency::MXN->value);
            $table->string('timezone')->default('America/Mexico_City');
            $table->string('date_format', 20)->default('d/m/Y');
            $table->string('time_format', 20)->default('H:i');
            $table->unsignedTinyInteger('decimal_precision')->default(2);
            $table->string('primary_color', 7)->default('#2563EB');
            $table->string('secondary_color', 7)->default('#10B981');
            $table->string('theme')->default(CompanyTheme::Modern->value);
            $table->string('mode')->default(CompanyMode::System->value);
            $table->string('default_cfdi_series')->nullable();
            $table->string('default_cfdi_usage', 3)->nullable();
            $table->string('default_payment_form', 2)->nullable();
            $table->string('default_payment_method', 2)->nullable();
            $table->string('expedition_place')->nullable();
            $table->string('fiscal_provider')->default(CompanyFiscalProvider::SAT->value);
            $table->boolean('sandbox')->default(false);
            $table->string('api_key')->nullable();
            $table->string('integration_status')->default(CompanyIntegrationStatus::Pending->value);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
