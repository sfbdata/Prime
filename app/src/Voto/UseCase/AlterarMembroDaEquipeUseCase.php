<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\MembroDaEquipeInput;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\EdicaoDaEquipe;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Altera nome, e-mail, cargo e permissão de uma linha da equipe (o lápis da tela Perfis). Só a Administração, só em
 * rascunho. A ligação com a conta é refeita pelo e-mail: trocar o e-mail pode tirar (ou dar) a conta da linha.
 */
final class AlterarMembroDaEquipeUseCase
{
    public function __construct(
        private readonly EdicaoDaEquipe $edicao,
        private readonly MembroDaEquipeRepository $membros,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    /**
     * @throws \App\Voto\Exception\EquipeSoDaAdministracaoException
     * @throws AssembleiaNaoEncontradaException
     * @throws OperacaoInvalidaNaAssembleiaException
     */
    public function executar(User $ator, Tenant $tenant, int $assembleiaId, int $membroId, MembroDaEquipeInput $input): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($ator, $tenant, $assembleiaId, $membroId, $input): void {
            $assembleia = $this->edicao->assembleiaEditavel($ator, $tenant, $assembleiaId);
            $membro = $this->membros->buscarDaAssembleia($membroId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $papel = PapelNaEquipe::tryFrom($input->papel) ?? throw new OperacaoInvalidaNaAssembleiaException('Escolha a permissão da pessoa.');
            $conta = $this->edicao->contaDoEmail($tenant, $input->email);
            $outra = null === $conta ? null : $this->membros->buscarDaPessoa($assembleia, $conta);
            if (null !== $outra && $outra->getId() !== $membro->getId()) {
                throw new OperacaoInvalidaNaAssembleiaException('Uma pessoa não pode acumular perfis na mesma votação.');
            }
            $membro->alterar($papel, $input->nome, $input->email, $input->cargo, $conta);
            $this->membros->salvar($membro, flush: true);
        });
    }
}
