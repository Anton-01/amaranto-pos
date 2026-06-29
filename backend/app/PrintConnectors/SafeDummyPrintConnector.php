<?php

namespace App\PrintConnectors;

use Mike42\Escpos\PrintConnectors\PrintConnector;

class SafeDummyPrintConnector implements PrintConnector
{
    private array $buffer = [];

    public function write($data)
    {
        $this->buffer[] = (string)$data;
    }

    public function read($len)
    {
        return "";
    }
    public function finalize()
    {
        // No-op
    }
    public function getData(): string
    {
        return implode('', $this->buffer);
    }
    public function clear(): void
    {
        $this->buffer = [];
    }
}
