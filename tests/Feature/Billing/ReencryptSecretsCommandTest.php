<?php

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27b (9): security:reencrypt vuelve a cifrar con la APP_KEY actual
 * lo cifrado con una llave anterior, comprobando la ida y vuelta.
 */
function phase627bKey(): string
{
    return Encrypter::generateKey((string) config('app.cipher'));
}

function phase627bEncrypter(string $key, array $previous = []): Encrypter
{
    return (new Encrypter($key, (string) config('app.cipher')))->previousKeys($previous);
}

/** Simula la rotación: la app cifra con $current y descifra también con $previous. */
function phase627bRotate(string $current, array $previous): void
{
    Crypt::swap(phase627bEncrypter($current, $previous));
}

/**
 * Una fila por columna cifrada con $key, más una ilegible (otra llave).
 *
 * @return array{company: Company, invoice: Invoice, event: int}
 */
function phase627bEncryptedRows(string $key): array
{
    $old = phase627bEncrypter($key);
    $company = Company::factory()->withFacturapiOrganization()->create();
    $invoice = Invoice::factory()->create(['company_id' => $company->id]);

    DB::table('facturapi_organizations')->where('company_id', $company->id)->update(['test_secret_key' => $old->encryptString('sk_test_ROTATION_SECRET_KEY_9999')]);
    DB::table('invoices')->where('id', $invoice->id)->update([
        'pac_response' => $old->encryptString(json_encode(['id' => 'inv_rotation', 'organization' => 'org_rotation'])),
        'pac_draft_response' => $old->encryptString(json_encode(['id' => 'draft_rotation'])),
        'updated_at' => '2026-10-01 00:00:00',
    ]);
    $event = DB::table('invoice_pac_events')->insertGetId([
        'company_id' => $company->id,
        'invoice_id' => $invoice->id,
        'event_type' => 'stamp_succeeded',
        'context' => $old->encryptString(json_encode(['attempt' => 1])),
        'occurred_at' => now(),
        'created_at' => now(),
    ]);

    return ['company' => $company, 'invoice' => $invoice, 'event' => $event];
}

function phase627bReencrypt(array $options = []): array
{
    $exit = Artisan::call('security:reencrypt', $options);

    return [$exit, Artisan::output()];
}

test('dry-run cuenta lo cifrado con la llave anterior y no escribe nada', function () {
    $old = phase627bKey();
    phase627bEncryptedRows($old);
    phase627bRotate(phase627bKey(), [$old]);
    $before = DB::table('invoices')->get(['pac_response', 'pac_draft_response', 'updated_at'])->toArray();

    [$exit, $output] = phase627bReencrypt();

    expect($exit)->toBe(0)
        ->and($output)->toContain('dry-run')
        ->and($output)->toContain('Llaves anteriores configuradas (APP_PREVIOUS_KEYS): 1')
        ->and($output)->toMatch('/invoice_pac_events\.context\s*\|\s*0\s*\|\s*1\s*\|\s*0/')
        ->and($output)->not->toContain('ROTATION_SECRET_KEY')
        ->and(DB::table('invoices')->get(['pac_response', 'pac_draft_response', 'updated_at'])->toArray())->toEqual($before);
});

test('--apply vuelve a cifrar con la llave actual sin cambiar el contenido ni updated_at', function () {
    $old = phase627bKey();
    $new = phase627bKey();
    $rows = phase627bEncryptedRows($old);
    phase627bRotate($new, [$old]);

    [$exit, $output] = phase627bReencrypt(['--apply' => true]);

    $currentOnly = phase627bEncrypter($new);
    $invoice = DB::table('invoices')->where('id', $rows['invoice']->id)->first();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('ROTATION_SECRET_KEY')
        ->and($currentOnly->decryptString(DB::table('facturapi_organizations')->where('company_id', $rows['company']->id)->value('test_secret_key')))->toBe('sk_test_ROTATION_SECRET_KEY_9999')
        ->and(json_decode($currentOnly->decryptString($invoice->pac_response), true))->toBe(['id' => 'inv_rotation', 'organization' => 'org_rotation'])
        ->and(json_decode($currentOnly->decryptString($invoice->pac_draft_response), true))->toBe(['id' => 'draft_rotation'])
        ->and(json_decode($currentOnly->decryptString(DB::table('invoice_pac_events')->where('id', $rows['event'])->value('context')), true))->toBe(['attempt' => 1])
        ->and($invoice->updated_at)->toBe('2026-10-01 00:00:00');

    // Ya sin la llave anterior, el modelo sigue leyendo todo.
    phase627bRotate($new, []);
    app(CurrentTenant::class)->set($rows['company']->id);
    expect(Invoice::query()->find($rows['invoice']->id)->pac_response['id'])->toBe('inv_rotation');

    // Repetirlo no encuentra nada pendiente.
    [, $again] = phase627bReencrypt(['--apply' => true]);
    expect($again)->toMatch('/invoices\.pac_response\s*\|\s*1\s*\|\s*0\s*\|\s*0/');
});

test('lo cifrado con la llave actual no se toca', function () {
    $current = phase627bKey();
    phase627bRotate($current, []);
    $rows = phase627bEncryptedRows($current);
    $before = DB::table('invoices')->where('id', $rows['invoice']->id)->value('pac_response');

    [$exit] = phase627bReencrypt(['--apply' => true]);

    expect($exit)->toBe(0)
        ->and(DB::table('invoices')->where('id', $rows['invoice']->id)->value('pac_response'))->toBe($before);
});

test('un valor que no descifra con ninguna llave se reporta, no se toca y falla', function () {
    $unknown = phase627bKey();
    $rows = phase627bEncryptedRows($unknown);
    phase627bRotate(phase627bKey(), [phase627bKey()]);
    $before = DB::table('invoices')->where('id', $rows['invoice']->id)->value('pac_response');

    [$exit, $output] = phase627bReencrypt(['--apply' => true]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('no se pueden descifrar con ninguna llave')
        ->and(DB::table('invoices')->where('id', $rows['invoice']->id)->value('pac_response'))->toBe($before);
});
