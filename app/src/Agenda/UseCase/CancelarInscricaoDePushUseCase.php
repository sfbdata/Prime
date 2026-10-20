<?php

declare(strict_types=1);

namespace App\Agenda\UseCase;

use App\Agenda\Repository\InscricaoDePushRepository;
use App\Entity\Auth\User;

/**
 * História: ao sair (ou ao revogar a permissão), o navegador deixa de receber o push desta pessoa. Só a
 * própria inscrição, em qualquer escritório (quem trocou de escritório na aba ainda cancela a linha antiga).
 */
final class CancelarInscricaoDePushUseCase
{
    public function __construct(
        private readonly InscricaoDePushRepository $inscricoes,
    ) {
    }

    public function executar(User $eu, string $endpoint): void
    {
        $this->inscricoes->removerDaPessoa($endpoint, $eu);
    }
}
