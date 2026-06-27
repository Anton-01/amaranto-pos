<?php

namespace App\Services\PrintConnectors;

use Mike42\Escpos\PrintConnectors\PrintConnector;
use RuntimeException;

class SambaAuthPrintConnector implements PrintConnector
{
    private string $buffer = '';

    public function __construct(
        private readonly string $host,
        private readonly string $share,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {}

    public function __destruct()
    {
        if ($this->buffer !== '') {
            trigger_error("Print connector was not finalized. Data may have been lost.", E_USER_NOTICE);
        }
    }

    public function write($data): void
    {
        $this->buffer .= $data;
    }

    public function read($len): string|false
    {
        return false;
    }

    public function finalize(): void
    {
        $sharePath = sprintf('//%s/%s', $this->host, $this->share);

        if ($this->user && $this->password) {
            $auth = sprintf('%s%%%s', $this->user, $this->password);
            $cmd = sprintf(
                'smbclient %s -c %s -U %s -m SMB2',
                escapeshellarg($sharePath),
                escapeshellarg('print -'),
                escapeshellarg($auth),
            );
        } else {
            $cmd = sprintf(
                'smbclient %s -c %s -N -m SMB2',
                escapeshellarg($sharePath),
                escapeshellarg('print -'),
            );
        }

        $process = popen($cmd, 'w');
        if (! is_resource($process)) {
            throw new RuntimeException('No se pudo inicializar el proceso smbclient.');
        }

        fwrite($process, $this->buffer);
        $retval = pclose($process);
        $this->buffer = '';

        if ($retval !== 0) {
            throw new RuntimeException("smbclient falló con código de salida: {$retval}");
        }
    }
}
