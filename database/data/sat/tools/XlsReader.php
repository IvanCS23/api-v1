<?php

/**
 * Lector mínimo, sin dependencias, de libros Excel 97-2003 (.xls, BIFF8
 * dentro de un contenedor OLE2/CFB). Existe sólo para convertir el
 * catálogo oficial del SAT (catCFDI_V_4_*.xls) a CSV de forma repetible
 * (ver convert_catalog.php y el README de database/data/sat).
 *
 * Soporta lo que usa ese archivo: hojas (BOUNDSHEET), tabla de cadenas
 * compartidas (SST con CONTINUE), y celdas LABELSST, LABEL, NUMBER, RK,
 * MULRK, FORMULA (resultado numérico o de cadena) y BOOLERR. Ignora
 * formato, estilos y fórmulas. No ejecuta nada del archivo: sólo lee bytes.
 */
final class XlsReader
{
    private string $workbook;

    /** @var list<string> */
    private array $sst = [];

    /** @var array<string, int> nombre de hoja => offset del BOF */
    private array $sheets = [];

    public function __construct(string $path)
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("No se pudo leer {$path}");
        }
        $this->workbook = $this->extractWorkbookStream($data);
        unset($data);
        $this->parseGlobals();
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * Filas de una hoja: lista de filas, cada una lista de celdas (string,
     * float o null), ordenadas por columna.
     *
     * @return array<int, array<int, string|float|null>>
     */
    public function rows(string $sheet): array
    {
        if (! isset($this->sheets[$sheet])) {
            throw new RuntimeException("La hoja {$sheet} no existe");
        }

        $rows = [];
        $pos = $this->sheets[$sheet];
        $len = strlen($this->workbook);
        $pendingFormula = null;

        while ($pos + 4 <= $len) {
            ['type' => $type, 'size' => $size] = unpack('vtype/vsize', substr($this->workbook, $pos, 4));
            $rec = substr($this->workbook, $pos + 4, $size);
            $pos += 4 + $size;

            switch ($type) {
                case 0x000A: // EOF
                    break 2;
                case 0x00FD: // LABELSST
                    $r = unpack('vrow/vcol/vxf/Visst', $rec);
                    $rows[$r['row']][$r['col']] = $this->sst[$r['isst']] ?? '';
                    break;
                case 0x0204: // LABEL
                    $r = unpack('vrow/vcol/vxf/vcch/Cflags', $rec);
                    $rows[$r['row']][$r['col']] = $this->decodeChars(substr($rec, 9), $r['cch'], ($r['flags'] & 1) === 1);
                    break;
                case 0x0203: // NUMBER
                    $r = unpack('vrow/vcol/vxf/enum', $rec);
                    $rows[$r['row']][$r['col']] = $r['num'];
                    break;
                case 0x027E: // RK
                    $r = unpack('vrow/vcol/vxf/Vrk', $rec);
                    $rows[$r['row']][$r['col']] = $this->decodeRk($r['rk']);
                    break;
                case 0x00BD: // MULRK
                    $row = unpack('v', $rec, 0)[1];
                    $first = unpack('v', $rec, 2)[1];
                    $count = intdiv($size - 6, 6);
                    for ($i = 0; $i < $count; $i++) {
                        $rk = unpack('V', $rec, 4 + $i * 6 + 2)[1];
                        $rows[$row][$first + $i] = $this->decodeRk($rk);
                    }
                    break;
                case 0x0006: // FORMULA
                    $r = unpack('vrow/vcol/vxf', $rec);
                    $result = substr($rec, 6, 8);
                    if (substr($result, 6, 2) === "\xFF\xFF") {
                        if (ord($result[0]) === 0) {
                            $pendingFormula = [$r['row'], $r['col']]; // la cadena llega en STRING
                        } else {
                            $rows[$r['row']][$r['col']] = null;
                        }
                    } else {
                        $rows[$r['row']][$r['col']] = unpack('e', $result)[1];
                    }
                    break;
                case 0x0207: // STRING (resultado de la fórmula anterior)
                    if ($pendingFormula !== null) {
                        $r = unpack('vcch/Cflags', $rec);
                        $rows[$pendingFormula[0]][$pendingFormula[1]] = $this->decodeChars(substr($rec, 3), $r['cch'], ($r['flags'] & 1) === 1);
                        $pendingFormula = null;
                    }
                    break;
                case 0x0205: // BOOLERR
                    $r = unpack('vrow/vcol/vxf/Cval/Cerr', $rec);
                    $rows[$r['row']][$r['col']] = $r['err'] === 0 ? (float) $r['val'] : null;
                    break;
            }
        }

        ksort($rows);
        foreach ($rows as &$cells) {
            ksort($cells);
        }

        return $rows;
    }

    private function decodeRk(int $rk): float
    {
        if ($rk & 0x02) {
            $value = (float) ($rk >> 2);
            if ($rk & 0x80000000) { // entero de 30 bits con signo
                $value = (float) (($rk >> 2) - (1 << 30));
            }
        } else {
            $value = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1];
        }

        return ($rk & 0x01) ? $value / 100 : $value;
    }

    private function decodeChars(string $bytes, int $cch, bool $highByte): string
    {
        if ($highByte) {
            return mb_convert_encoding(substr($bytes, 0, $cch * 2), 'UTF-8', 'UTF-16LE');
        }

        return mb_convert_encoding(substr($bytes, 0, $cch), 'UTF-8', 'ISO-8859-1');
    }

    private function parseGlobals(): void
    {
        $pos = 0;
        $len = strlen($this->workbook);
        $sstChunks = null;

        while ($pos + 4 <= $len) {
            ['type' => $type, 'size' => $size] = unpack('vtype/vsize', substr($this->workbook, $pos, 4));
            $rec = substr($this->workbook, $pos + 4, $size);
            $pos += 4 + $size;

            if ($sstChunks !== null) {
                if ($type === 0x003C) { // CONTINUE de la SST
                    $sstChunks[] = $rec;

                    continue;
                }
                $this->parseSst($sstChunks);
                $sstChunks = null;
            }

            if ($type === 0x0085) { // BOUNDSHEET
                $r = unpack('Voffset/Cvisibility/Ctype/Ccch/Cflags', $rec);
                $name = $this->decodeChars(substr($rec, 8), $r['cch'], ($r['flags'] & 1) === 1);
                if ($r['type'] === 0) { // hoja de cálculo
                    $this->sheets[$name] = $r['offset'];
                }
            } elseif ($type === 0x00FC) { // SST
                $sstChunks = [$rec];
            } elseif ($type === 0x000A) { // EOF de los globales
                break;
            }
        }

        if ($sstChunks !== null) {
            $this->parseSst($sstChunks);
        }
    }

    /** @param list<string> $chunks */
    private function parseSst(array $chunks): void
    {
        $chunk = 0;
        $offset = 8; // cstTotal + cstUnique
        $unique = unpack('V', $chunks[0], 4)[1];

        $raw = function (int $n) use (&$chunk, &$offset, $chunks): string {
            $out = '';
            while ($n > 0) {
                if ($offset >= strlen($chunks[$chunk])) {
                    $chunk++;
                    $offset = 0;
                }
                $take = min($n, strlen($chunks[$chunk]) - $offset);
                $out .= substr($chunks[$chunk], $offset, $take);
                $offset += $take;
                $n -= $take;
            }

            return $out;
        };

        for ($i = 0; $i < $unique; $i++) {
            ['cch' => $cch] = unpack('vcch', $raw(2));
            $flags = ord($raw(1));
            $highByte = ($flags & 0x01) === 1;
            $runs = ($flags & 0x08) ? unpack('v', $raw(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $raw(4))[1] : 0;

            $text = '';
            $remaining = $cch;
            while ($remaining > 0) {
                if ($offset >= strlen($chunks[$chunk])) {
                    // Los caracteres continúan en el siguiente CONTINUE, que
                    // empieza con su propio byte de opciones (ancho de carácter).
                    $chunk++;
                    $offset = 0;
                    $highByte = (ord($chunks[$chunk][0]) & 0x01) === 1;
                    $offset = 1;
                }
                $width = $highByte ? 2 : 1;
                $available = intdiv(strlen($chunks[$chunk]) - $offset, $width);
                $take = min($remaining, $available);
                $text .= $this->decodeChars(substr($chunks[$chunk], $offset, $take * $width), $take, $highByte);
                $offset += $take * $width;
                $remaining -= $take;
            }

            if ($runs > 0) {
                $raw($runs * 4);
            }
            if ($ext > 0) {
                $raw($ext);
            }

            $this->sst[] = $text;
        }
    }

    /** Extrae el stream "Workbook" (o "Book") del contenedor OLE2. */
    private function extractWorkbookStream(string $data): string
    {
        if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('El archivo no es un contenedor OLE2 (.xls)');
        }

        $sectorSize = 1 << unpack('v', $data, 30)[1];
        $miniSectorSize = 1 << unpack('v', $data, 32)[1];
        $dirStart = unpack('V', $data, 48)[1];
        $miniCutoff = unpack('V', $data, 56)[1];
        $miniFatStart = unpack('V', $data, 60)[1];
        $difatStart = unpack('V', $data, 68)[1];
        $difatCount = unpack('V', $data, 72)[1];

        $sector = fn (int $id): string => substr($data, ($id + 1) * $sectorSize, $sectorSize);

        // DIFAT: 109 entradas en la cabecera y el resto encadenado.
        $fatSectors = array_values(unpack('V109', $data, 76));
        $next = $difatStart;
        for ($i = 0; $i < $difatCount && $next < 0xFFFFFFFA; $i++) {
            $entries = array_values(unpack('V'.($sectorSize / 4), $sector($next)));
            $next = array_pop($entries);
            array_push($fatSectors, ...$entries);
        }

        $fat = [];
        foreach ($fatSectors as $id) {
            if ($id >= 0xFFFFFFFA) {
                continue;
            }
            array_push($fat, ...array_values(unpack('V'.($sectorSize / 4), $sector($id))));
        }

        $chain = function (int $start) use ($fat, $sector): string {
            $out = [];
            $guard = 0;
            for ($id = $start; $id < 0xFFFFFFFA; $id = $fat[$id]) {
                $out[] = $sector($id);
                if (++$guard > count($fat)) {
                    throw new RuntimeException('Cadena FAT circular');
                }
            }

            return implode('', $out);
        };

        $directory = $chain($dirStart);
        $root = null;
        $book = null;
        for ($off = 0; $off + 128 <= strlen($directory); $off += 128) {
            $nameLen = unpack('v', $directory, $off + 64)[1];
            if ($nameLen < 2) {
                continue;
            }
            $name = mb_convert_encoding(substr($directory, $off, $nameLen - 2), 'UTF-8', 'UTF-16LE');
            $entry = [
                'type' => ord($directory[$off + 66]),
                'start' => unpack('V', $directory, $off + 116)[1],
                'size' => unpack('V', $directory, $off + 120)[1],
            ];
            if ($entry['type'] === 5) {
                $root = $entry;
            } elseif ($entry['type'] === 2 && ($name === 'Workbook' || ($name === 'Book' && $book === null))) {
                $book = $entry;
            }
        }

        if ($book === null) {
            throw new RuntimeException('El .xls no contiene el stream Workbook');
        }

        if ($book['size'] >= $miniCutoff) {
            return substr($chain($book['start']), 0, $book['size']);
        }

        // Stream pequeño: vive en el mini stream del directorio raíz.
        $miniStream = $chain($root['start']);
        $miniFat = array_values(unpack('V*', $chain($miniFatStart)));
        $out = '';
        for ($id = $book['start']; $id < 0xFFFFFFFA; $id = $miniFat[$id]) {
            $out .= substr($miniStream, $id * $miniSectorSize, $miniSectorSize);
        }

        return substr($out, 0, $book['size']);
    }
}
