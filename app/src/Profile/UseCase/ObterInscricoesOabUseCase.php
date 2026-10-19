<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabOutput;

/**
 * Inscrições da pessoa para o card "Registro OAB" do Meu Perfil. Quem entrou por cadastro, convite ou
 * criar escritório tem a OAB só no espelho User.oab*: na primeira leitura a principal é criada a partir
 * dele (EspelhoDaOabPrincipal, escrita única e idempotente, para a tela já ter o id que o modal edita).
 */
final class ObterInscricoesOabUseCase
{
    public function __construct(
        private readonly EspelhoDaOabPrincipal $espelho,
    ) {
    }

    /** @return list<InscricaoOabOutput> principal primeiro, depois por UF */
    public function executar(User $usuario): array
    {
        return array_map(
            static fn ($i): InscricaoOabOutput => InscricaoOabOutput::fromEntity($i),
            $this->espelho->inscricoesDe($usuario),
        );
    }
}
