<?php

namespace App\Services\Remesas;

use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Genera el fichero de adeudos SEPA (cuaderno 19) en formato pain.008.001.02.
 *
 * Se construye con DOMDocument y no con SimpleXMLElement porque
 * SimpleXMLElement::addChild() no escapa el ampersand: ante un valor como
 * "PEREZ & HIJOS" emite un warning de entidad sin terminar y escribe el
 * elemento VACIO, de modo que el nombre del deudor desaparecía del adeudo sin
 * dejar rastro en el log. DOMDocument, además, sabe indentar la salida.
 */
class Q19Generator implements GenerarRemesaInterface
{
    /** Campos del acreedor que el fichero exige sí o sí. */
    private const CAMPOS_EMPRESA = ['nombre', 'iban', 'bic', 'identificador_sepa'];

    public function generar(
        Collection $giros,
        array $empresa,
        string $referencia,
        string $fechaCobro,
        string $tipoPorDefecto = 'FRST'
    ): string {
        $this->validarEmpresa($empresa);

        if ($giros->isEmpty()) {
            throw new InvalidArgumentException('No hay giros que incluir en la remesa.');
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $documento = $doc->createElementNS('urn:iso:std:iso:20022:tech:xsd:pain.008.001.02', 'Document');
        $doc->appendChild($documento);
        $init = $this->hijo($doc, $documento, 'CstmrDrctDbtInitn');

        $this->cabecera($doc, $init, $giros, $empresa, $referencia);

        // SeqTp va a nivel de PmtInf, así que los adeudos primeros (FRST) y los
        // recurrentes (RCUR) no pueden convivir en el mismo bloque: se agrupan
        // por tipo_adeudo y cada grupo genera su propio PmtInf.
        $grupos = $giros->groupBy(fn ($giro) => strtoupper(trim($giro->tipo_adeudo ?: $tipoPorDefecto)));

        $indiceGlobal = 0;
        foreach ($grupos as $secuencia => $girosDelGrupo) {
            $this->bloqueDePago(
                $doc,
                $init,
                $girosDelGrupo,
                $empresa,
                $grupos->count() > 1 ? "{$referencia}-{$secuencia}" : $referencia,
                $referencia,
                $fechaCobro,
                $secuencia,
                $indiceGlobal
            );
        }

        return $doc->saveXML();
    }

    private function cabecera(
        DOMDocument $doc,
        DOMNode $init,
        Collection $giros,
        array $empresa,
        string $referencia
    ): void {
        $grpHdr = $this->hijo($doc, $init, 'GrpHdr');
        $this->hijo($doc, $grpHdr, 'MsgId', $this->recortar($referencia, 35));
        // Sin desfase horario: el Q19 espera YYYY-MM-DDThh:mm:ss y las entidades
        // españolas rechazan el "+02:00" que añadía toIso8601String().
        $this->hijo($doc, $grpHdr, 'CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $this->hijo($doc, $grpHdr, 'NbOfTxs', (string) $giros->count());
        $this->hijo($doc, $grpHdr, 'CtrlSum', $this->importe($giros->sum('importe')));
        $initgPty = $this->hijo($doc, $grpHdr, 'InitgPty');
        $this->hijo($doc, $initgPty, 'Nm', $this->recortar($empresa['nombre'], 70));
    }

    private function bloqueDePago(
        DOMDocument $doc,
        DOMNode $init,
        Collection $giros,
        array $empresa,
        string $pmtInfId,
        string $referencia,
        string $fechaCobro,
        string $secuencia,
        int &$indiceGlobal
    ): void {
        $pmtInf = $this->hijo($doc, $init, 'PmtInf');
        $this->hijo($doc, $pmtInf, 'PmtInfId', $this->recortar($pmtInfId, 35));
        $this->hijo($doc, $pmtInf, 'PmtMtd', 'DD');
        $this->hijo($doc, $pmtInf, 'BtchBookg', 'true');
        $this->hijo($doc, $pmtInf, 'NbOfTxs', (string) $giros->count());
        $this->hijo($doc, $pmtInf, 'CtrlSum', $this->importe($giros->sum('importe')));

        $pmtTpInf = $this->hijo($doc, $pmtInf, 'PmtTpInf');
        $this->hijo($doc, $this->hijo($doc, $pmtTpInf, 'SvcLvl'), 'Cd', 'SEPA');
        // LclInstrm es obligatorio para la banca española: CORE (básico) frente
        // a B2B (entre empresas). Todos los mandatos de la aplicación son CORE.
        $this->hijo($doc, $this->hijo($doc, $pmtTpInf, 'LclInstrm'), 'Cd', 'CORE');
        $this->hijo($doc, $pmtTpInf, 'SeqTp', $secuencia);

        $this->hijo($doc, $pmtInf, 'ReqdColltnDt', $this->fecha($fechaCobro));

        $cdtr = $this->hijo($doc, $pmtInf, 'Cdtr');
        $this->hijo($doc, $cdtr, 'Nm', $this->recortar($empresa['nombre'], 70));

        $cdtrAcctId = $this->hijo($doc, $this->hijo($doc, $pmtInf, 'CdtrAcct'), 'Id');
        $this->hijo($doc, $cdtrAcctId, 'IBAN', $this->iban($empresa['iban']));

        $cdtrAgt = $this->hijo($doc, $this->hijo($doc, $pmtInf, 'CdtrAgt'), 'FinInstnId');
        $this->hijo($doc, $cdtrAgt, 'BIC', strtoupper(trim($empresa['bic'])));

        // Reparto de gastos: SLEV (cada parte paga los suyos) es el único valor
        // admitido en SEPA.
        $this->hijo($doc, $pmtInf, 'ChrgBr', 'SLEV');

        $cdtrSchmeId = $this->hijo($doc, $pmtInf, 'CdtrSchmeId');
        $prvtId = $this->hijo($doc, $this->hijo($doc, $cdtrSchmeId, 'Id'), 'PrvtId');
        $othr = $this->hijo($doc, $prvtId, 'Othr');
        $this->hijo($doc, $othr, 'Id', trim($empresa['identificador_sepa']));
        $this->hijo($doc, $this->hijo($doc, $othr, 'SchmeNm'), 'Prtry', 'SEPA');

        foreach ($giros as $giro) {
            $indiceGlobal++;
            $this->adeudo($doc, $pmtInf, $giro, $referencia, $indiceGlobal);
        }
    }

    private function adeudo(DOMDocument $doc, DOMNode $pmtInf, $giro, string $referencia, int $indice): void
    {
        $tx = $this->hijo($doc, $pmtInf, 'DrctDbtTxInf');

        $pmtId = $this->hijo($doc, $tx, 'PmtId');
        $referenciaAdeudo = $giro->referencia_adeudo ?: "{$referencia}_{$indice}";
        $this->hijo($doc, $pmtId, 'EndToEndId', $this->recortar($referenciaAdeudo, 35));

        $instdAmt = $this->hijo($doc, $tx, 'InstdAmt', $this->importe($giro->importe));
        $instdAmt->setAttribute('Ccy', 'EUR');

        $mandato = $this->hijo($doc, $this->hijo($doc, $tx, 'DrctDbtTx'), 'MndtRltdInf');
        $this->hijo($doc, $mandato, 'MndtId', $this->recortar($giro->referencia_mandato, 35));
        $this->hijo($doc, $mandato, 'DtOfSgntr', $this->fecha($giro->fecha_firma_mandato));

        // El BIC del banco del deudor no se guarda en ninguna parte: el campo
        // 'auxiliar' del giro es el comercial, no un código bancario. Para
        // adeudos SEPA dentro de la zona IBAN el BIC es opcional y la forma
        // correcta de omitirlo es NOTPROVIDED; el banco lo deduce del IBAN.
        $dbtrAgt = $this->hijo($doc, $this->hijo($doc, $tx, 'DbtrAgt'), 'FinInstnId');
        $this->hijo($doc, $this->hijo($doc, $dbtrAgt, 'Othr'), 'Id', 'NOTPROVIDED');

        $dbtr = $this->hijo($doc, $tx, 'Dbtr');
        $this->hijo($doc, $dbtr, 'Nm', $this->recortar($giro->nombre_cliente, 70));

        $dbtrAcctId = $this->hijo($doc, $this->hijo($doc, $tx, 'DbtrAcct'), 'Id');
        $this->hijo($doc, $dbtrAcctId, 'IBAN', $this->iban($giro->iban_cliente));

        $rmtInf = $this->hijo($doc, $tx, 'RmtInf');
        $this->hijo($doc, $rmtInf, 'Ustrd', $this->recortar($giro->concepto, 140));
    }

    /**
     * Crea un elemento y lo cuelga del padre. El texto se añade como nodo de
     * texto, que es lo que garantiza el escapado de &, < y >.
     */
    private function hijo(DOMDocument $doc, DOMNode $padre, string $nombre, ?string $valor = null): DOMElement
    {
        $nodo = $doc->createElement($nombre);

        if ($valor !== null && $valor !== '') {
            $nodo->appendChild($doc->createTextNode($valor));
        }

        $padre->appendChild($nodo);

        return $nodo;
    }

    /**
     * Recorta respetando los caracteres multibyte: substr() a secas parte por
     * la mitad una "ñ" o una vocal acentuada que caiga justo en el límite.
     */
    private function recortar(?string $valor, int $longitud): string
    {
        return mb_substr(trim((string) $valor), 0, $longitud);
    }

    private function importe($valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /** El tipo ISODate del esquema no admite hora: sólo YYYY-MM-DD. */
    private function fecha($valor): string
    {
        return Carbon::parse($valor)->format('Y-m-d');
    }

    private function iban(?string $valor): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $valor));
    }

    private function validarEmpresa(array $empresa): void
    {
        $faltan = array_values(array_filter(
            self::CAMPOS_EMPRESA,
            fn ($campo) => empty($empresa[$campo])
        ));

        if ($faltan) {
            throw new InvalidArgumentException(
                'Faltan datos de remesa del acreedor: ' . implode(', ', $faltan)
            );
        }
    }
}
