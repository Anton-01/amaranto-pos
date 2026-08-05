<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Ficha de identidad de un punto de restauracion (Fase 10).
 *
 * Se persiste junto al artefacto como `<nombre>.manifest.json` y es la FUENTE
 * DE VERDAD para validar integridad antes de un rollback: el checksum aqui
 * declarado se recalcula sobre el artefacto descargado y ambos deben coincidir
 * o la restauracion se aborta.
 *
 * @implements Arrayable<string, mixed>
 */
class BackupManifest implements Arrayable
{
    public const VERSION = 1;

    /**
     * @param  list<string>  $contents
     */
    public function __construct(
        public readonly string $name,
        public readonly string $artifact,
        public readonly string $checksum,
        public readonly int $size_bytes,
        public readonly bool $encrypted,
        public readonly string $cipher,
        public readonly string $database,
        public readonly string $created_at,
        public readonly ?string $created_by_id,
        public readonly ?string $created_by_name,
        public readonly string $trigger,
        public readonly array $contents,
        public readonly string $app_env,
        public readonly int $manifest_version = self::VERSION,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            artifact: (string) ($data['artifact'] ?? ''),
            checksum: (string) ($data['checksum'] ?? ''),
            size_bytes: (int) ($data['size_bytes'] ?? 0),
            encrypted: (bool) ($data['encrypted'] ?? false),
            cipher: (string) ($data['cipher'] ?? ''),
            database: (string) ($data['database'] ?? ''),
            created_at: (string) ($data['created_at'] ?? ''),
            created_by_id: $data['created_by_id'] ?? null,
            created_by_name: $data['created_by_name'] ?? null,
            trigger: (string) ($data['trigger'] ?? 'unknown'),
            contents: array_values((array) ($data['contents'] ?? [])),
            app_env: (string) ($data['app_env'] ?? 'unknown'),
            manifest_version: (int) ($data['manifest_version'] ?? self::VERSION),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manifest_version' => $this->manifest_version,
            'name' => $this->name,
            'artifact' => $this->artifact,
            'checksum' => $this->checksum,
            'checksum_algorithm' => 'sha256',
            'size_bytes' => $this->size_bytes,
            'encrypted' => $this->encrypted,
            'cipher' => $this->cipher,
            'database' => $this->database,
            'created_at' => $this->created_at,
            'created_by_id' => $this->created_by_id,
            'created_by_name' => $this->created_by_name,
            'trigger' => $this->trigger,
            'contents' => $this->contents,
            'app_env' => $this->app_env,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, $unit === 0 ? 0 : 2).' '.$units[$unit];
    }
}
