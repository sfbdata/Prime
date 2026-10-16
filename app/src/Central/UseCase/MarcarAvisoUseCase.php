<?php

declare(strict_types=1);

namespace App\Central\UseCase;

use App\Central\Exception\AvisoNaoEncontradoException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\Service\AcessoACentral;
use App\Central\Service\AvisosDaCentral;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marca um aviso do sino como lido ou não lido pela Central (F1). Só o PRÓPRIO aviso, neste escritório e que aparece na
 * Caixa; o de outra pessoa (ou de outro escritório, ou de gestão) é "não encontrado", como conversa alheia.
 */
final class MarcarAvisoUseCase
{
    public function __construct(
        private readonly AcessoACentral $acesso,
        private readonly AvisosDaCentral $avisos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @throws SemAcessoACentralException
     * @throws AvisoNaoEncontradoException
     */
    public function executar(User $eu, ?Tenant $tenant, int $id, bool $lido): void
    {
        $tenant = $this->acesso->exigirVinculoAtivo($eu, $tenant);
        $aviso = $this->avisos->doUsuario($id, $eu, $tenant);
        if ($aviso === null) {
            throw new AvisoNaoEncontradoException();
        }

        $aviso->setLida($lido);
        $this->em->flush();
    }
}
