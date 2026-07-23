<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCompanyId extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:backfill-company-id
        {--company= : ID de la empresa que recibirá los registros huérfanos}
        {--dry-run : Analiza los cambios sin guardar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Asigna company_id a los registros huérfanos (NULL) de clients, products y employes.';

    /**
     * Tablas de catálogo que todavía pueden tener company_id NULL.
     *
     * Se usa Query Builder en todo el comando (no Eloquent) para no depender
     * de scopes globales, eventos de modelo ni lógica futura que se agregue
     * a Client/Product/Employe.
     *
     * @var list<string>
     */
    private const TABLES = ['clients', 'products', 'employes'];

    public function handle(): int
    {
        $orphanCounts = collect(self::TABLES)
            ->mapWithKeys(fn (string $table): array => [
                $table => DB::table($table)->whereNull('company_id')->count(),
            ]);

        if ($orphanCounts->sum() === 0) {
            $this->info('No se encontraron registros huérfanos (company_id NULL) en clients, products ni employes. Nada que hacer.');

            return self::SUCCESS;
        }

        $companiesCount = DB::table('companies')->count();

        if ($companiesCount === 0) {
            $this->error('No existen empresas registradas. Crea al menos una empresa antes de ejecutar este comando.');

            return self::FAILURE;
        }

        $companyOption = $this->option('company');

        if ($companyOption !== null) {
            $company = DB::table('companies')->where('id', $companyOption)->first();

            if ($company === null) {
                $this->error("La empresa con ID [{$companyOption}] no existe.");

                return self::FAILURE;
            }
        } elseif ($companiesCount === 1) {
            $company = DB::table('companies')->first();

            $this->info("No se especificó --company. Se detectó una única empresa registrada (ID: {$company->id} - {$company->legal_name}); se usará automáticamente.");
        } else {
            $this->error("Existen {$companiesCount} empresas registradas. Debes especificar --company=<id> para indicar cuál recibirá los registros huérfanos.");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        $this->table(
            ['Tabla', 'Registros huérfanos', 'Empresa destino', 'Modo'],
            collect(self::TABLES)->map(fn (string $table): array => [
                $table,
                $orphanCounts->get($table),
                "{$company->id} - {$company->legal_name}",
                $isDryRun ? 'dry-run' : 'escritura',
            ])->all()
        );

        if ($isDryRun) {
            $this->info('Modo dry-run: no se modificó ningún registro.');

            return self::SUCCESS;
        }

        $updatedCounts = [];

        DB::transaction(function () use ($company, &$updatedCounts): void {
            foreach (self::TABLES as $table) {
                $updatedCounts[$table] = DB::table($table)
                    ->whereNull('company_id')
                    ->update(['company_id' => $company->id]);
            }
        });

        $this->newLine();
        $this->info('Registros actualizados:');

        $this->table(
            ['Tabla', 'Filas actualizadas'],
            collect($updatedCounts)
                ->map(fn (int $count, string $table): array => [$table, $count])
                ->values()
                ->all()
        );

        return self::SUCCESS;
    }
}
