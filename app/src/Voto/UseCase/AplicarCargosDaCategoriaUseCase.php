<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\EdicaoDaEquipe;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * "Aplicar cargos da categoria" da tela Perfis: cria uma linha "A designar" para cada cargo pronto da categoria
 * escolhida ({@see CenarioDaAssembleia::cargosDaEquipe()}), sem Guardião e Validação. Cargo que a equipe já tem (mesmo
 * texto, sem ligar para caixa) não é repetido. Só a Administração, só em rascunho. Devolve quantas linhas criou.
 *
 * Linha "A designar" não dá acesso a ninguém (não tem conta): com a equipe só de "A designar", quem não é a
 * Administração deixa de ver a assembleia até ser indicado.
 */
final class AplicarCargosDaCategoriaUseCase
{
    public function __construct(
        private readonly EdicaoDaEquipe $edicao,
        private readonly MembroDaEquipeRepository $membros,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    /**
     * @throws \App\Voto\Exception\EquipeSoDaAdministracaoException
     * @throws \App\Voto\Exception\AssembleiaNaoEncontradaException
     * @throws \App\Voto\Exception\OperacaoInvalidaNaAssembleiaException
     */
    public function executar(User $ator, Tenant $tenant, int $assembleiaId, CenarioDaAssembleia $categoria): int
    {
        return $this->serializacao->executar($tenant, $assembleiaId, function () use ($ator, $tenant, $assembleiaId, $categoria): int {
            $assembleia = $this->edicao->assembleiaEditavel($ator, $tenant, $assembleiaId);
            $jaTem = [];
            foreach ($this->membros->daAssembleia($assembleia) as $existente) {
                $jaTem[mb_strtolower($existente->getCargo())] = true;
            }
            $novas = [];
            foreach ($categoria->cargosDaEquipe() as $cargo) {
                if (!isset($jaTem[mb_strtolower($cargo['cargo'])])) {
                    $novas[] = new MembroDaEquipe($assembleia, $cargo['papel'], MembroDaEquipe::NOME_A_DESIGNAR, null, $cargo['cargo']);
                }
            }
            $this->membros->salvarVarios($novas);
            $criadas = \count($novas);

            return $criadas;
        });
    }
}
