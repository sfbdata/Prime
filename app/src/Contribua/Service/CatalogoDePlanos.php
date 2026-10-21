<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use App\Contribua\DTO\PlanoDoCatalogo;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\PlanoRepository;

/** Leitura do catálogo para o painel do Master (contrato com o domínio Perfis). */
final class CatalogoDePlanos
{
    public function __construct(
        private readonly PlanoRepository $planos,
    ) {
    }

    /** @return list<PlanoDoCatalogo> todos os planos, ativos ou não, na ordem da tela */
    public function todos(): array
    {
        $escritorios = $this->planos->escritoriosPorPlano();

        return array_map(
            static fn (Plano $p): PlanoDoCatalogo => PlanoDoCatalogo::de($p, $escritorios[$p->getCodigo()] ?? 0),
            $this->planos->findBy([], ['ordem' => 'ASC']),
        );
    }
}
