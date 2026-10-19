<?php

declare(strict_types=1);

namespace App\Profile\UseCase;

use App\Auth\Repository\InscricaoOabRepository;
use App\Auth\Service\EspelhoDaOabPrincipal;
use App\Entity\Auth\User;
use App\Profile\DTO\InscricaoOabOutput;

/**
 * Inscrições da pessoa para o card "Registro OAB" do Meu Perfil. Quem entrou por cadastro, convite ou
 * criar escritório tem a OAB só no espelho User.oab*: na primeira leitura a principal é criada a partir
 * dele e gravada (escrita única e idempotente, para a tela já ter o id que o modal edita).
 */
final class ObterInscricoesOabUseCase
{
    public function __construct(
        private readonly EspelhoDaOabPrincipal $espelho,
        private readonly InscricaoOabRepository $inscricoes,
    ) {
    }

    /** @return list<InscricaoOabOutput> principal primeiro, depois por UF */
    public function executar(User $usuario): array
    {
        $lista = $this->espelho->inscricoesDe($usuario);
        foreach ($lista as $inscricao) {
            if ($inscricao->getId() === null) {
                $this->inscricoes->salvar($inscricao, flush: true);
            }
        }

        return array_map(static fn ($i): InscricaoOabOutput => InscricaoOabOutput::fromEntity($i), $lista);
    }
}
