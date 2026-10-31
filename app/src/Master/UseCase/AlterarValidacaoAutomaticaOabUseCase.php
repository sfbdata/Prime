<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;

/**
 * Liga/desliga e frequência da validação automática da Revisão de OAB (desenho 05 - Perfis 1.2 de 31/10).
 *
 * Só "Ao cadastrar" é aceita: Diária e Semanal precisam da consulta ao CNA, que está desligada (decisão do dono:
 * revalidação periódica fica fora de escopo). Ao cadastrar, o formato da OAB já é conferido pelo
 * {@see \App\Auth\Service\ValidadorOab::validarFormato()}; o CNA, quando ligado, completa a conferência.
 * Grava em configuracao_global, sem tocar nos campos do catálogo ({@see ConfiguracaoGlobal::definirRevisaoOab()}).
 *
 * @throws \DomainException frequência que não é "cadastro"
 */
final class AlterarValidacaoAutomaticaOabUseCase
{
    public function __construct(
        private readonly ConfiguracaoGlobalRepository $configuracoes,
    ) {
    }

    public function executar(bool $ligada, string $frequencia): void
    {
        if ($frequencia !== 'cadastro') {
            throw new \DomainException('Disponível quando a consulta ao CNA estiver ligada.');
        }

        $linha = $this->configuracoes->buscarUnica() ?? new ConfiguracaoGlobal();
        $linha->definirRevisaoOab(['ligada' => $ligada ? '1' : '0', 'frequencia' => $frequencia]);
        $this->configuracoes->salvar($linha, flush: true);
    }
}
