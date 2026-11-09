<?php

/**
 * Fase 6.33 (7): compuerta de `composer audit` para CI.
 *
 * Uso:
 *   composer audit --locked --no-dev --abandoned=ignore --format=json > composer-audit.json || true
 *   php scripts/ci/audit-gate.php composer-audit.json security/accepted-advisories.json
 *
 * Falla (código 1) sólo si:
 * - hay un aviso alto o crítico de producción que no está en la lista de
 *   aceptados, o
 * - una aceptación ya pasó su fecha de revisión (`review_by`).
 * Los avisos medios, bajos y sin severidad se listan, pero no fallan.
 * Una salida de composer que no se puede leer también falla (código 2):
 * nunca se da por buena una auditoría que no corrió.
 */
$auditFile = $argv[1] ?? null;
$acceptedFile = $argv[2] ?? null;

if ($auditFile === null || $acceptedFile === null) {
    fwrite(STDERR, "Uso: php scripts/ci/audit-gate.php <composer-audit.json> <accepted-advisories.json>\n");
    exit(2);
}

$audit = json_decode((string) @file_get_contents($auditFile), true);
if (! is_array($audit) || ! array_key_exists('advisories', $audit)) {
    fwrite(STDERR, "La salida de composer audit no se pudo leer ({$auditFile}).\n");
    exit(2);
}

$acceptedData = json_decode((string) @file_get_contents($acceptedFile), true);
if (! is_array($acceptedData) || ! is_array($acceptedData['composer'] ?? null)) {
    fwrite(STDERR, "La lista de avisos aceptados no se pudo leer o no tiene la llave \"composer\" ({$acceptedFile}).\n");
    exit(2);
}

$today = gmdate('Y-m-d');
$failed = false;
$accepted = [];

foreach ($acceptedData['composer'] as $entry) {
    foreach (['id', 'package', 'severity', 'reason', 'accepted_on', 'review_by'] as $field) {
        if (! is_string($entry[$field] ?? null) || $entry[$field] === '') {
            fwrite(STDERR, "Aceptación incompleta: falta \"{$field}\" en ".json_encode($entry)."\n");
            exit(2);
        }
    }

    if ($entry['review_by'] < $today) {
        echo "FALLA  aceptación vencida: {$entry['id']} ({$entry['package']}) debía revisarse el {$entry['review_by']}.\n";
        $failed = true;
    }

    $accepted[$entry['package'].'|'.$entry['id']] = $entry;
}

$seen = [];
$count = 0;

foreach ($audit['advisories'] as $package => $advisories) {
    foreach ($advisories as $advisory) {
        $count++;
        $severity = strtolower((string) ($advisory['severity'] ?? '')) ?: 'sin severidad';
        $ids = array_values(array_filter([$advisory['advisoryId'] ?? null, $advisory['cve'] ?? null]));
        $label = ($ids[1] ?? $ids[0] ?? '?').' '.$package.' ['.$severity.'] '.($advisory['title'] ?? '');

        $match = null;
        foreach ($ids as $id) {
            if (isset($accepted[$package.'|'.$id])) {
                $match = $package.'|'.$id;
                $seen[$match] = true;
            }
        }

        if ($match !== null) {
            echo "acept. {$label} (revisar el {$accepted[$match]['review_by']})\n";
        } elseif (in_array($severity, ['high', 'critical'], true)) {
            echo "FALLA  {$label}\n";
            $failed = true;
        } else {
            echo "aviso  {$label}\n";
        }
    }
}

foreach (array_diff_key($accepted, $seen) as $key => $entry) {
    echo "nota   {$entry['id']} ({$entry['package']}) ya no aparece: se puede quitar de la lista.\n";
}

echo $failed
    ? "composer audit: hay avisos altos o críticos de producción sin aceptar, o aceptaciones vencidas.\n"
    : "composer audit: {$count} avisos de producción; ninguno alto o crítico sin aceptar.\n";

exit($failed ? 1 : 0);
