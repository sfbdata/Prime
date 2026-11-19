<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;

/**
 * Um grupo de ações da `documentos_e_pecas` (documentos e subpastas, peças e modelos, checklist). A ação inteira é
 * `chave.operacao` ({@see DocumentosEPecasAcoes::junta()}): a chave é a da {@see TabelaDeAbaEChave}, a operação é o
 * campo `operacao` da entrada.
 *
 * O trio de métodos é o que o contrato do {@see ExecutorDeProposta} pede: {@see conferir()} confere sem gravar (serve ao
 * propor e à reconferência), {@see resumir()} escreve o resumo da proposta e {@see gravar()} grava pelos UseCases.
 */
interface DocumentosEPecasGrupo
{
    /** @return list<string> as chaves da tabela que o grupo cuida */
    public function chaves(): array;

    /**
     * Confere de novo que tudo o que a ação toca é desta pasta e deste escritório e continua valendo; não grava nada.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed> o que achou (entidades), para {@see resumir()} e {@see gravar()}
     *
     * @throws ErroDaFerramenta
     */
    public function conferir(string $acao, array $campos, DocumentosEPecasContexto $contexto): array;

    /**
     * O resumo, em português, do que a proposta vai fazer (só as ações por proposta).
     *
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $achados o que {@see conferir()} devolveu
     */
    public function resumir(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): string;

    /**
     * Grava pelo UseCase que a tela usa. O resultado vai para o app de I.A. (direta) ou para a `confirmar_acao`.
     *
     * @param array<string, mixed> $campos
     * @param array<string, mixed> $achados o que {@see conferir()} devolveu
     *
     * @throws ErroDaFerramenta
     */
    public function gravar(string $acao, array $campos, array $achados, DocumentosEPecasContexto $contexto): ResultadoDaConfirmacao;
}
