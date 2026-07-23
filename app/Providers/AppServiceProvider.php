<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Employe;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Policies\ClientPolicy;
use App\Policies\EmployePolicy;
use App\Policies\ProductPolicy;
use App\Policies\QuoteItemPolicy;
use App\Policies\QuotePolicy;
use App\Policies\SaleItemPolicy;
use App\Policies\SalePolicy;
use App\Support\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configurePolicies();

        // Sin este flag, cualquier API Resource devuelta directamente se
        // envuelve en {"data": ...}, lo que rompería el contrato JSON plano
        // que ya consume app-front (ver client.services.ts, que no
        // desenvuelve `.data` en create/update/show).
        JsonResource::withoutWrapping();
    }

    /**
     * Registrar policies explícitamente (sin depender de auto-discovery).
     */
    protected function configurePolicies(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Employe::class, EmployePolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(SaleItem::class, SaleItemPolicy::class);
        Gate::policy(Quote::class, QuotePolicy::class);
        Gate::policy(QuoteItem::class, QuoteItemPolicy::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
