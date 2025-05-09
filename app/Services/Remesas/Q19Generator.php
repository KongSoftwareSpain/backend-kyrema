<?php

namespace App\Services\Remesas;

use SimpleXMLElement;

class Q19Generator implements GenerarRemesaInterface
{
    public function generar($giros, array $empresa, string $referencia, string $fechaCobro, string $tipo = 'FRST'): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02">
    <CstmrDrctDbtInitn/>
</Document>');

        $init = $xml->CstmrDrctDbtInitn;

        $grupo = $init->addChild('GrpHdr');
        $grupo->addChild('MsgId', $referencia);
        $grupo->addChild('CreDtTm', now()->toIso8601String());
        $grupo->addChild('NbOfTxs', $giros->count());
        $grupo->addChild('CtrlSum', number_format($giros->sum('importe'), 2, '.', ''));
        $grupo->addChild('InitgPty')->addChild('Nm', substr($empresa['nombre'], 0, 70));

        $pmtInf = $init->addChild('PmtInf');
        $pmtInf->addChild('PmtInfId', $referencia);
        $pmtInf->addChild('PmtMtd', 'DD');
        $pmtInf->addChild('BtchBookg', 'true');
        $pmtInf->addChild('NbOfTxs', $giros->count());
        $pmtInf->addChild('CtrlSum', number_format($giros->sum('importe'), 2, '.', ''));

        $pmtTpInf = $pmtInf->addChild('PmtTpInf');
        $pmtTpInf->addChild('SvcLvl')->addChild('Cd', 'SEPA');
        $pmtTpInf->addChild('SeqTp', $tipo);
        $pmtInf->addChild('ReqdColltnDt', $fechaCobro);

        $pmtInf->addChild('Cdtr')->addChild('Nm', substr($empresa['nombre'], 0, 70));
        $pmtInf->addChild('CdtrAcct')->addChild('Id')->addChild('IBAN', $empresa['iban']);
        $pmtInf->addChild('CdtrAgt')->addChild('FinInstnId')->addChild('BIC', $empresa['bic']);
        $pmtInf->addChild('CdtrSchmeId')
            ->addChild('Id')
            ->addChild('PrvtId')
            ->addChild('Othr')
            ->addChild('Id', $empresa['identificador_sepa'])
            ->addChild('SchmeNm')
            ->addChild('Prtry', 'SEPA');

        foreach ($giros as $index => $op) {
            $tx = $pmtInf->addChild('DrctDbtTxInf');
            $tx->addChild('PmtId')->addChild('EndToEndId', $op->referencia_adeudo ?? $referencia . '_' . ($index + 1));
            $tx->addChild('InstdAmt', number_format($op->importe, 2, '.', ''))->addAttribute('Ccy', 'EUR');

            $mandato = $tx->addChild('DrctDbtTx')->addChild('MndtRltdInf');
            $mandato->addChild('MndtId', $op->referencia_mandato);
            $mandato->addChild('DtOfSgntr', $op->fecha_firma_mandato);

            $tx->addChild('DbtrAgt')->addChild('FinInstnId')->addChild('BIC', $op->pago->auxiliar ?? 'UNKNOWN');
            $tx->addChild('Dbtr')->addChild('Nm', substr($op->nombre_cliente, 0, 70));
            $tx->addChild('DbtrAcct')->addChild('Id')->addChild('IBAN', $op->iban_cliente);
            $tx->addChild('RmtInf')->addChild('Ustrd', substr($op->concepto, 0, 140));
        }

        return $xml->asXML();
    }
}
