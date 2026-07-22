<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\ObjetoDetalheOutput;
use App\Cobranca\DTO\VinculoPessoaOutput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Entity\Auth\User;

/**
 * Leitura: monta o detalhe da página unificada do Objeto (ajuste 2). O caso âncora já vem resolvido e
 * validado por tenant no controller (via `CasoCobrancaRepository::casoAncoraDoObjeto`); aqui só delega
 * o corpo operacional ao `MontarDetalheCasoUseCase` e agrega os vínculos do objeto, marcando quem é a
 * pessoa cobrada atual. Nada recalcula regra de negócio — só lê e formata via Output DTOs.
 */
final class MontarDetalheObjetoUseCase
{
    public function __construct(
        private readonly MontarDetalheCasoUseCase $montarDetalheCaso,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
    ) {
    }

    /** `$usuarioAtual` só decide quais anotações do histórico este leitor pode corrigir (2026-07-22). */
    public function executar(ObjetoCobranca $objeto, CasoCobranca $caso, ?User $usuarioAtual = null): ObjetoDetalheOutput
    {
        $casoDetalhe = $this->montarDetalheCaso->executar($caso, $usuarioAtual);
        $pessoaCobradaId = $caso->getPessoaCobradaAtual()?->getId();

        $vinculos = array_map(
            static fn (VinculoPessoaObjeto $vinculo): VinculoPessoaOutput => VinculoPessoaOutput::fromEntity($vinculo, $pessoaCobradaId),
            $this->vinculoRepository->todosDoObjetoComPessoa($objeto),
        );

        $carteira = $objeto->getCarteira();

        return new ObjetoDetalheOutput(
            objetoId: $objeto->getId() ?? 0,
            identificacao: $objeto->getIdentificacao(),
            descricao: $objeto->getDescricao(),
            referenciaExterna: $objeto->getReferenciaExterna(),
            carteiraId: $carteira?->getId() ?? 0,
            carteiraNome: $carteira?->getNome() ?? '—',
            caso: $casoDetalhe,
            temCobradaAtual: $pessoaCobradaId !== null,
            vinculos: $vinculos,
        );
    }
}
