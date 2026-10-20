<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Contribua\Entity\Plano;

/** Um plano como o painel do Master o mostra: os valores e quantos escritórios estão nele. */
final readonly class PlanoDoCatalogo
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public int $cotaGb,
        public int $precoCentavos,
        public ?int $limiteUsuarios,
        public string $descricao,
        public bool $ativo,
        public int $ordem,
        public int $escritorios,
    ) {
    }

    public static function de(Plano $plano, int $escritorios): self
    {
        return new self(
            $plano->getCodigo(),
            $plano->getNome(),
            $plano->getCotaGb(),
            $plano->getPrecoCentavos(),
            $plano->getLimiteUsuarios(),
            $plano->getDescricao(),
            $plano->isAtivo(),
            $plano->getOrdem(),
            $escritorios,
        );
    }
}
