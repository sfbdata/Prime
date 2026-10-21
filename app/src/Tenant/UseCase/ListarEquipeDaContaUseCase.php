<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tenant\DTO\MembroDaEquipeOutput;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\Repository\EquipeDoEscritorioRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Equipe da conta para a aba "Minha conta" (desenho bj-contas.js; spec docs/specs/equipe-da-conta.md §1): só o primário
 * do escritório ou o Master vê. Inclui quem está em análise ou suspenso (que não aparecem nas outras listas do sistema).
 */
final class ListarEquipeDaContaUseCase
{
    public function __construct(
        private readonly EquipeDoEscritorioRepository $equipe,
        private readonly RegraDosPrimarios $regra,
    ) {
    }

    /**
     * @return list<MembroDaEquipeOutput> primários primeiro, depois por nome
     *
     * @throws OperacaoDePrimarioRecusada
     */
    public function executar(User $ator, Tenant $tenant): array
    {
        if (!$this->regra->podeGerirPrimarios($ator, $tenant)) {
            throw new OperacaoDePrimarioRecusada('Somente o usuário primário pode gerir a equipe da conta.');
        }

        return array_map(
            fn ($v): MembroDaEquipeOutput => MembroDaEquipeOutput::fromEntity($v, $this->regra->ehMaster($v->getUser()), $v->getUser()->getId() === $ator->getId()),
            $this->equipe->membrosDaEquipe($tenant),
        );
    }
}
