<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;

/**
 * O que o `PastaRecebidaDoUsuario::localizar` achou (spec acesso-restrito-e-compartilhar §4.5.1): o convite aceito e
 * vigente, a pasta e a conta de origem (A), quem republicou e o que vale para quem recebeu. Fica no servidor: o Twig
 * recebe o `PastaRecebidaOutput`, nunca isto.
 *
 *  - `soLeitura`: a conta de origem está suspensa (fora do ar sem ter sido excluída);
 *  - `abasOcultas`: as abas que somem para quem recebeu (as ocultas para quem republicou e as "para todos").
 */
final readonly class PastaRecebida
{
    /** @param list<string> $abasOcultas valores de {@see AbaDaPasta} */
    public function __construct(
        public PastaCompartilhamento $compartilhamento,
        public Pasta $pasta,
        public Tenant $tenant,
        public User $origem,
        public bool $soLeitura,
        public array $abasOcultas,
    ) {
    }

    public function compartilhamentoId(): int
    {
        return (int) $this->compartilhamento->getId();
    }

    public function pastaId(): int
    {
        return (int) $this->pasta->getId();
    }

    public function abaVisivel(AbaDaPasta $aba): bool
    {
        return !\in_array($aba->value, $this->abasOcultas, true);
    }
}
