<?php

namespace Tests\Unit;

use App\Builders\TicketBuilder;
use App\DTOs\TicketDTO;
use App\DTOs\TicketItemDTO;
use PHPUnit\Framework\TestCase;

class TicketBuilderTest extends TestCase
{
    private TicketBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new TicketBuilder();
    }

    public function test_pad_line_produces_exactly_32_characters(): void
    {
        $line = $this->builder->padLine('Subtotal:', '$1,234.56');

        $this->assertEquals(32, mb_strlen($line));
        $this->assertStringStartsWith('Subtotal:', $line);
        $this->assertStringEndsWith('$1,234.56', $line);
    }

    public function test_pad_line_truncates_long_left_text(): void
    {
        $line = $this->builder->padLine('Este es un texto extremadamente largo', '$99.00');

        $this->assertEquals(32, mb_strlen($line));
        $this->assertStringEndsWith('$99.00', $line);
        $this->assertStringContainsString('..', $line);
    }

    public function test_separator_is_exactly_32_characters(): void
    {
        $sep = $this->builder->separator('-');
        $this->assertEquals(32, mb_strlen($sep));
        $this->assertEquals(str_repeat('-', 32), $sep);

        $sep2 = $this->builder->separator('=');
        $this->assertEquals(32, mb_strlen($sep2));
        $this->assertEquals(str_repeat('=', 32), $sep2);
    }

    public function test_format_product_line_short_name(): void
    {
        $item = new TicketItemDTO(
            nombreVariacion: 'Coca Cola',
            cantidad: 2,
            precioFinal: '$25.00',
            totalItem: '$50.00',
        );

        $lines = $this->builder->formatProductLine($item);

        $this->assertCount(1, $lines);
        $this->assertEquals(32, mb_strlen($lines[0]));
        $this->assertStringStartsWith('2 x Coca Cola', $lines[0]);
        $this->assertStringEndsWith('$50.00', $lines[0]);
    }

    public function test_format_product_line_long_name_truncated(): void
    {
        $item = new TicketItemDTO(
            nombreVariacion: 'Hamburguesa Especial con Queso',
            cantidad: 2,
            precioFinal: '$120.00',
            totalItem: '$240.00',
        );

        $lines = $this->builder->formatProductLine($item);

        $this->assertCount(1, $lines);
        $this->assertEquals(32, mb_strlen($lines[0]));
        $this->assertStringStartsWith('2 x ', $lines[0]);
        $this->assertStringEndsWith('$240.00', $lines[0]);
        $this->assertStringContainsString('..', $lines[0]);
    }

    public function test_inverse_tax_calculation(): void
    {
        $publicPrice = 90.00;
        $taxRate = 0.16;

        $subtotal = round($publicPrice / (1 + $taxRate), 2);
        $iva = round($publicPrice - $subtotal, 2);

        $this->assertEquals(77.59, $subtotal);
        $this->assertEquals(12.41, $iva);
        $this->assertEquals($publicPrice, $subtotal + $iva);
    }

    public function test_inverse_tax_with_multiple_items(): void
    {
        $taxRate = 0.16;

        $items = [
            ['price' => 50.00, 'qty' => 3],
            ['price' => 120.00, 'qty' => 1],
            ['price' => 25.50, 'qty' => 2],
        ];

        $totalGross = 0;
        foreach ($items as $item) {
            $totalGross += $item['price'] * $item['qty'];
        }

        $this->assertEquals(321.00, $totalGross);

        $subtotal = round($totalGross / (1 + $taxRate), 2);
        $iva = round($totalGross - $subtotal, 2);

        $this->assertEquals(276.72, $subtotal);
        $this->assertEquals(44.28, $iva);
        $this->assertEquals($totalGross, $subtotal + $iva);
    }

    public function test_build_text_lines_all_within_32_chars(): void
    {
        $dto = new TicketDTO(
            empresa: 'Cronos POS Restaurante',
            rfc: 'XAXX010101000',
            direccion: 'Av. Reforma 123, Col. Centro, CDMX',
            telefono: '55-1234-5678',
            mensajeCabecera: 'Gracias por su visita',
            folio: 'A1B2C3D4',
            fechaHora: '25/06/2026 14:30:00',
            metodoPago: 'EFECTIVO',
            operador: 'Juan Perez',
            items: [
                new TicketItemDTO('Hamburguesa Especial con Queso', 2, '$120.00', '$240.00'),
                new TicketItemDTO('Coca Cola 600ml', 3, '$25.00', '$75.00'),
                new TicketItemDTO('Papas Grandes', 1, '$45.00', '$45.00'),
            ],
            subtotalNeto: '$310.34',
            ivaMonto: '$49.66',
            ivaLabel: 'IVA 16%',
            totalPublico: '$360.00',
            recibido: '$400.00',
            cambio: '$40.00',
            // Ticket sin descuento. El parametro es nullable pero no tiene
            // valor por defecto, asi que hay que pasarlo explicitamente: la
            // prueba es anterior al modulo de descuentos y llevaba tiempo
            // rompiendo la suite por esta omision.
            descuentoTotal: null,
            leyendaPersonalizada: 'Mesa 5 - Pedido especial sin cebolla',
            mensajePie: 'Vuelva pronto!',
            version: 3,
            qrContent: 'http://localhost/orders/test-uuid',
        );

        $lines = $this->builder->buildTextLines($dto);

        $this->assertNotEmpty($lines);

        foreach ($lines as $index => $line) {
            $this->assertLessThanOrEqual(
                32,
                mb_strlen($line),
                "Line {$index} exceeds 32 chars ({$line}) — length: " . mb_strlen($line)
            );
        }
    }

    public function test_center_text(): void
    {
        $centered = $this->builder->centerText('TOTAL');
        $this->assertLessThanOrEqual(32, mb_strlen($centered));
        $this->assertStringContainsString('TOTAL', $centered);
    }

    public function test_wrap_text_splits_long_strings(): void
    {
        $text = 'Esta es una leyenda personalizada muy larga que deberia ser dividida en multiples lineas para el ticket';
        $lines = $this->builder->wrapText($text, 32);

        $this->assertGreaterThan(1, count($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(32, mb_strlen($line));
        }
    }

    public function test_format_money(): void
    {
        $this->assertEquals('$1,234.56', $this->builder->formatMoney(1234.56));
        $this->assertEquals('$0.00', $this->builder->formatMoney(0));
        $this->assertEquals('$99.90', $this->builder->formatMoney(99.90));
    }
}
