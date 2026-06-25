<?php

namespace App\Services;

use App\Builders\TicketBuilder;
use App\DTOs\TicketDTO;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;
use RuntimeException;

class PrinterService
{
    public function __construct(
        private readonly TicketBuilder $builder,
    ) {}

    public function print(TicketDTO $dto): void
    {
        $connector = $this->createConnector();
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
    }

    private function createConnector(): FilePrintConnector|NetworkPrintConnector|WindowsPrintConnector
    {
        $type = config('printer.connection_type', 'network');

        return match ($type) {
            'windows' => new WindowsPrintConnector(config('printer.windows_share')),
            'file' => new FilePrintConnector(config('printer.file_path', '/dev/usb/lp0')),
            'network' => new NetworkPrintConnector(
                config('printer.ip_address', '192.168.1.100'),
                (int) config('printer.port', 9100),
            ),
            default => throw new RuntimeException("Tipo de conexión de impresora no soportado: {$type}"),
        };
    }

    private function configureCodePage(Printer $printer): void
    {
        // CP858 supports Spanish characters (ñ, accents)
        $printer->selectCharacterTable(13);
    }

    private function printHeader(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->text($this->encode($dto->empresa) . "\n");
        $printer->setEmphasis(false);

        if ($dto->rfc) {
            $printer->text($this->encode('RFC: ' . $dto->rfc) . "\n");
        }
        if ($dto->direccion) {
            foreach ($this->builder->wrapText($dto->direccion, TicketBuilder::LINE_WIDTH) as $line) {
                $printer->text($this->encode($line) . "\n");
            }
        }
        if ($dto->telefono) {
            $printer->text($this->encode('Tel: ' . $dto->telefono) . "\n");
        }
        if ($dto->mensajeCabecera) {
            $printer->feed(1);
            foreach ($this->builder->wrapText($dto->mensajeCabecera, TicketBuilder::LINE_WIDTH) as $line) {
                $printer->text($this->encode($line) . "\n");
            }
        }
    }

    private function printMetadata(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text($this->encode($this->builder->separator('=')) . "\n");
        $printer->text($this->encode($this->builder->padLine('Folio:', $dto->folio)) . "\n");
        $printer->text($this->encode($this->builder->padLine('Fecha:', $dto->fechaHora)) . "\n");
        $printer->text($this->encode($this->builder->padLine('Pago:', $dto->metodoPago)) . "\n");
        $printer->text($this->encode($this->builder->padLine('Operador:', $dto->operador)) . "\n");
        $printer->text($this->encode($this->builder->separator('=')) . "\n");
    }

    private function printItems(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text($this->encode($this->builder->padLine('PRODUCTO', 'IMPORTE')) . "\n");
        $printer->text($this->encode($this->builder->separator('-')) . "\n");

        foreach ($dto->items as $item) {
            foreach ($this->builder->formatProductLine($item) as $line) {
                $printer->text($this->encode($line) . "\n");
            }
        }
    }

    private function printTotals(Printer $printer, TicketDTO $dto): void
    {
        $printer->setJustification(Printer::JUSTIFY_LEFT);

        $printer->text($this->encode($this->builder->separator('-')) . "\n");
        $printer->text($this->encode($this->builder->padLine('Subtotal:', $dto->subtotalNeto)) . "\n");
        $printer->text($this->encode($this->builder->padLine($dto->ivaLabel . ':', $dto->ivaMonto)) . "\n");
        $printer->text($this->encode($this->builder->separator('=')) . "\n");

        $printer->setEmphasis(true);
        $printer->text($this->encode($this->builder->padLine('TOTAL:', $dto->totalPublico)) . "\n");
        $printer->setEmphasis(false);

        if ($dto->recibido) {
            $printer->text($this->encode($this->builder->separator('-')) . "\n");
            $printer->text($this->encode($this->builder->padLine('Recibido:', $dto->recibido)) . "\n");
            $printer->setEmphasis(true);
            $printer->text($this->encode($this->builder->padLine('Cambio:', $dto->cambio ?? '$0.00')) . "\n");
            $printer->setEmphasis(false);
        }
    }

    private function printFooter(Printer $printer, TicketDTO $dto): void
    {
        if ($dto->leyendaPersonalizada) {
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_LEFT);
            foreach ($this->builder->wrapText($dto->leyendaPersonalizada, TicketBuilder::LINE_WIDTH) as $line) {
                $printer->text($this->encode($line) . "\n");
            }
        }

        if ($dto->mensajePie) {
            $printer->feed(1);
            $printer->setJustification(Printer::JUSTIFY_CENTER);
            foreach ($this->builder->wrapText($dto->mensajePie, TicketBuilder::LINE_WIDTH) as $line) {
                $printer->text($this->encode($line) . "\n");
            }
        }

        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->feed(1);
        $printer->text($this->encode('v' . $dto->version) . "\n");
    }

    private function printQr(Printer $printer, TicketDTO $dto): void
    {
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->qrCode($dto->qrContent, Printer::QR_ECLEVEL_M, 5, Printer::QR_MODEL_2);
    }

    private function encode(string $text): string
    {
        $encoded = iconv('UTF-8', 'CP858//TRANSLIT//IGNORE', $text);

        return $encoded !== false ? $encoded : $text;
    }
}
