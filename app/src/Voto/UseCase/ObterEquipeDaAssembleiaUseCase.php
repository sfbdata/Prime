<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\LinhaDaEquipe;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\MembroDaEquipeRepository;

/**
 * A equipe própria de UMA assembleia do escritório, para o passo 6 do assistente (desenho 18, H266). Leitura: quem chega
 * aqui já passou pela guarda do módulo e pela barreira da assembleia; só a Administração altera (UseCases da equipe).
 * Lista vazia = "Usar a equipe padrão".
 */
final class ObterEquipeDaAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly MembroDaEquipeRepository $membros,
    ) {
    }

    /**
     * @return list<LinhaDaEquipe>
     *
     * @throws AssembleiaNaoEncontradaException a assembleia não é do escritório
     */
    public function executar(Tenant $tenant, int $assembleiaId): array
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant) ?? throw new AssembleiaNaoEncontradaException();

        return array_map(LinhaDaEquipe::de(...), $this->membros->daAssembleia($assembleia));
    }
}
