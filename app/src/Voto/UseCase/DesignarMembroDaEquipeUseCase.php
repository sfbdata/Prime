<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\MembroDaEquipeInput;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\EdicaoDaEquipe;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * "Designar" da tela Perfis: acrescenta uma pessoa à equipe própria da assembleia. Só a Administração, só em rascunho.
 * Uma pessoa (conta do escritório, achada pelo e-mail) tem um único papel por assembleia. Com a primeira linha, a
 * assembleia passa de "equipe padrão" a "equipe própria" (e quem não está na lista deixa de vê-la).
 */
final class DesignarMembroDaEquipeUseCase
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
     * @throws OperacaoInvalidaNaAssembleiaException
     */
    public function executar(User $ator, Tenant $tenant, int $assembleiaId, MembroDaEquipeInput $input): int
    {
        return $this->serializacao->executar($tenant, $assembleiaId, function () use ($ator, $tenant, $assembleiaId, $input): int {
            $assembleia = $this->edicao->assembleiaEditavel($ator, $tenant, $assembleiaId);
            $papel = PapelNaEquipe::tryFrom($input->papel) ?? throw new OperacaoInvalidaNaAssembleiaException('Escolha a permissão da pessoa.');
            $conta = $this->edicao->contaDoEmail($tenant, $input->email);
            if (null !== $conta && null !== $this->membros->buscarDaPessoa($assembleia, $conta)) {
                throw new OperacaoInvalidaNaAssembleiaException('Uma pessoa não pode acumular perfis na mesma votação.');
            }
            $membro = new MembroDaEquipe($assembleia, $papel, $input->nome, $input->email, $input->cargo, $conta);
            $this->membros->salvar($membro, flush: true);

            return (int) $membro->getId();
        });
    }
}
