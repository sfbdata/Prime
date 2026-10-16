<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\DTO\ContatoOutput;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * Com quem dá para abrir conversa: os colegas com vínculo ativo no escritório (a mesma lista do filtro de
 * responsável), menos quem pede.
 */
final class ListarContatosDaCentralUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly UserRepository $usuarios,
    ) {
    }

    /**
     * @return list<ContatoOutput>
     *
     * @throws SemAcessoACentralException
     */
    public function executar(User $eu, ?Tenant $tenant): array
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);

        $contatos = [];
        foreach ($this->usuarios->findColaboradoresAtivosPorTenant($tenant) as $colega) {
            if ($colega->getId() !== $eu->getId()) {
                $contatos[] = new ContatoOutput((int) $colega->getId(), (string) $colega->getFullName());
            }
        }

        return $contatos;
    }
}
