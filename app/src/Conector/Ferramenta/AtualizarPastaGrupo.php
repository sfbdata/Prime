<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * Um grupo de ações da `atualizar_pasta` (cabeçalho, ciclo da pasta, marcadores, cadastro, avisos, exclusão). A
 * {@see AtualizarPastaFerramenta} acha o grupo pela ação e repassa. O contrato de cada método é o da ferramenta e o do
 * {@see ExecutorDeProposta}: até propor só lê; {@see reconferir()} nunca grava; {@see executarConfirmada()} confere de
 * novo antes de gravar, dentro da transação da confirmação.
 */
interface AtualizarPastaGrupo
{
    /** @return list<string> as ações (da tabela de aba e chave) que este grupo atende */
    public function acoes(): array;

    /** @throws ErroDaFerramenta */
    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta;

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void;

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao;
}
