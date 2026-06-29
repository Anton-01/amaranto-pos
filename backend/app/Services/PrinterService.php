<?php

namespace App\Services;

use App\Builders\TicketBuilder;
use App\DTOs\TicketDTO;
use App\PrintConnectors\SafeDummyPrintConnector;
use Mike42\Escpos\Printer;

class PrinterService
{
    public function __construct(
        private readonly TicketBuilder $builder,
    ) {}

    public function generateBase64(TicketDTO $dto): string
    {
        $connector = new SafeDummyPrintConnector();
        $printer = new Printer($connector);

        try {
            $this->configureCodePage($printer);
            $this->printHeader($printer, $dto);
            $this->printMetadata($printer, $dto);
            $this->printItems($printer, $dto);
            $this->printTotals($printer, $dto);
            $this->printFooter($printer, $dto);
            $this->printQr($printer, $dto);

            $printer->feed(3);
            $printer->cut();
        } finally {
            $printer->close();
        }

        return base64_encode($connector->getData());
    }

    private function configureCodePage(Printer $printer): void
    {
        $printer->selectCharacterTable(13);
    }

    private function writeRaw(Printer $printer, string $text): void
    {
        $encoded = iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $text);

        $printer->getPrintConnector()->write($encoded !== false ? $encoded : $text);
    }

    private function printHeader(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $this->writeRaw($printer, $dto->empresa . "\n");
        $printer->setEmphasis(false);

        if ($dto->rfc) {
            $this->writeRaw($printer, 'RFC: ' . $dto->rfc . "\n");
        }
        if ($dto->direccion) {
            foreach ($this->builder->wrapText($dto->direccion, TicketBuilder::LINE_WIDTH) as $line) {
                $this->writeRaw($printer, $line . "\n");
            }
        }
        if ($dto->telefono) {
            $this->writeRaw($printer, 'Tel: ' . $dto->telefono . "\n");
        }
        if ($dto->mensajeCabecera) {
            $printer->feed(1);
            foreach ($this->builder->wrapText($dto->mensajeCabecera, TicketBuilder::LINE_WIDTH) as $line) {
                $this->writeRaw($printer, $line . "\n");
            }
        }
    }

    private function printMetadata(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $this->writeRaw($printer, $this->builder->separator('=') . "\n");
        $this->writeRaw($printer, $this->builder->padLine('Folio:', $dto->folio) . "\n");
        $this->writeRaw($printer, $this->builder->padLine('Fecha:', $dto->fechaHora) . "\n");
        $this->writeRaw($printer, $this->builder->padLine('Pago:', $dto->metodoPago) . "\n");
        $this->writeRaw($printer, $this->builder->padLine('Operador:', $dto->operador) . "\n");
        $this->writeRaw($printer, $this->builder->separator('=') . "\n");
    }

    private function printItems(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $this->writeRaw($printer, $this->builder->padLine('PRODUCTO', 'IMPORTE') . "\n");
        $this->writeRaw($printer, $this->builder->separator('-') . "\n");

        foreach ($dto->items as $item) {
            foreach ($this->builder->formatProductLine($item) as $line) {
                $this->writeRaw($printer, $line . "\n");
            }
        }
    }

    private function printTotals(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $this->writeRaw($printer, $this->builder->separator('-') . "\n");

        if ($dto->descuentoTotal) {
            $printer->setEmphasis(true);
            $this->writeRaw($printer, $this->builder->padLine('Descuento:', '-' . $dto->descuentoTotal) . "\n");
            $printer->setEmphasis(false);
        }

        $this->writeRaw($printer, $this->builder->padLine('Subtotal:', $dto->subtotalNeto) . "\n");
        $this->writeRaw($printer, $this->builder->padLine($dto->ivaLabel . ':', $dto->ivaMonto) . "\n");
        $this->writeRaw($printer, $this->builder->separator('=') . "\n");

        $printer->setEmphasis(true);
        $this->writeRaw($printer, $this->builder->padLine('TOTAL:', $dto->totalPublico) . "\n");
        $printer->setEmphasis(false);

        if ($dto->recibido) {
            $this->writeRaw($printer, $this->builder->separator('-') . "\n");
            $this->writeRaw($printer, $this->builder->padLine('Recibido:', $dto->recibido) . "\n");
            $printer->setEmphasis(true);
            $this->writeRaw($printer, $this->builder->padLine('Cambio:', $dto->cambio ?? '$0.00') . "\n");
            $printer->setEmphasis(false);
        }
    }

    private function printFooter(Printer $printer, TicketDTO $dto): void
    {
        if ($dto->leyendaPersonalizada) {
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            foreach ($this->builder->wrapText($dto->leyendaPersonalizada, TicketBuilder::LINE_WIDTH) as $line) {
                $this->writeRaw($printer, $line . "\n");
            }
        }

        if ($dto->mensajePie) {
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            foreach ($this->builder->wrapText($dto->mensajePie, TicketBuilder::LINE_WIDTH) as $line) {
                $this->writeRaw($printer, $line . "\n");
            }
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed(1);
        $this->writeRaw($printer, 'v' . $dto->version . "\n");
    }

    private function printQr(Printer $printer, TicketDTO $dto): void
    {
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->qrCode($dto->qrContent, Printer::QR_ECLEVEL_M, 5, Printer::QR_MODEL_2);
    }
}
