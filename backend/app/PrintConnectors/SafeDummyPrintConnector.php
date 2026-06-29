<?php

namespace App\PrintConnectors;

use Mike42\Escpos\PrintConnectors\PrintConnector;

class SafeDummyPrintConnector implements PrintConnector
{
    private array $buffer = [];

    public function write(string $data): void
    {
        $this->buffer[] = $data;
    }

    public function getData(): string
    {
        return implode('', $this->buffer);
    }

    public function clear(): void
    {
        $this->buffer = [];
    }

    public function finalize(): void {}

    public function read(int $len): string
    {
        return '';
    }
}
