<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;

/**
 * "Remover esta inscrição" do modal da OAB (desenho 07, `ofPodeRemover`): só uma SUPLEMENTAR da própria
 * pessoa, e só quando ela tem mais de uma inscrição. A principal não sai (troca-se a principal), então o
 * espelho User.oab* não muda.
 *
 * @throws \DomainException
 */
final class RemoverInscricaoOabUseCase
{
    public function __construct(
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    public function executar(User $usuario, int $id): string
    {
        $inscricao = $this->inscricoes->daPessoa($id, $usuario);
        if ($inscricao === null) {
            throw new \DomainException('Inscrição não encontrada.');
        }
        if ($inscricao->ehPrincipal()) {
            throw new \DomainException('A inscrição principal não pode ser removida. Escolha outra como principal antes.');
        }

        $uf = $inscricao->getUf();
        $this->inscricoes->remover($inscricao, flush: true);

        return $uf;
    }
}
