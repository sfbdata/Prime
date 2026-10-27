<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Master\Enum\TipoDeRegistroDeBackup;

/** Registrar backup ou teste de restauração (POST /master/desempenho/backups, JSON `{tipo, ok, observacao?}`). */
final readonly class RegistrarBackupInput
{
    public function __construct(
        public ?User $ator,
        public TipoDeRegistroDeBackup $tipo,
        public bool $ok,
        public ?string $observacao = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): ?self
    {
        $tipo = TipoDeRegistroDeBackup::tryFrom(is_string($corpo['tipo'] ?? null) ? $corpo['tipo'] : '');
        if ($tipo === null || !is_bool($corpo['ok'] ?? null)) {
            return null;
        }

        return new self($ator, $tipo, $corpo['ok'], is_string($corpo['observacao'] ?? null) ? $corpo['observacao'] : null);
    }
}
