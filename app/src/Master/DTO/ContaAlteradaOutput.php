<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Tenant\Tenant;

/**
 * A conta depois de suspensa, reativada ou excluída: o painel troca a situação da linha sem recarregar.
 * Conta excluída fica "Em quarentena" até a purga (`purgaEm`, data Y-m-d), spec perfis-1.2-contas C4 e C4b.
 */
final readonly class ContaAlteradaOutput
{
    public const ATIVA = 'Ativa';
    public const SUSPENSA = 'Suspensa';
    public const EM_QUARENTENA = 'Em quarentena';

    public function __construct(
        public int $id,
        public string $nome,
        public string $situacao,
        public ?string $purgaEm = null,
    ) {
    }

    /** @param int $carenciaPurgaDias dias de quarentena antes da purga (`tenant_carencia_purga_dias`) */
    public static function fromEntity(Tenant $conta, int $carenciaPurgaDias): self
    {
        $excluidoEm = $conta->getExcluidoEm();

        return new self(
            (int) $conta->getId(),
            (string) $conta->getName(),
            self::situacao($conta->isActive() === true, $excluidoEm !== null),
            $excluidoEm?->modify(sprintf('+%d days', $carenciaPurgaDias))->format('Y-m-d'),
        );
    }

    /** Situação exibida no painel: a quarentena (excluidoEm) vem antes de isActive. */
    public static function situacao(bool $ativa, bool $excluida): string
    {
        if ($excluida) {
            return self::EM_QUARENTENA;
        }

        return $ativa ? self::ATIVA : self::SUSPENSA;
    }
}
