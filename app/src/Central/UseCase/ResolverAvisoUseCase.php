<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\AvisoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Repository\AvisoResolvidoRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisosDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Dar baixa (resolver) ou reabrir um aviso da Caixa (F3, `resolverNotif`, L5603-5630). Só o PRÓPRIO aviso, neste
 * escritório e que aparece na Caixa. Resolver também marca como lido (no desenho, `n.lida = true`).
 */
final class ResolverAvisoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly AvisosDaCentral $avisos,
        private readonly AvisoResolvidoRepository $resolvidos,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws AvisoNaoEncontradoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $id, bool $resolver): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $aviso = $this->avisos->doUsuario($id, $eu, $tenant);
        if ($aviso === null) {
            throw new AvisoNaoEncontradoException();
        }

        if (!$resolver) {
            $this->resolvidos->reabrir($tenant, $eu, $id);

            return;
        }

        $this->resolvidos->resolver((int) $tenant->getId(), (int) $eu->getId(), $id, $this->clock->now());
        $aviso->setLida(true);
        $this->em->flush();
    }
}
