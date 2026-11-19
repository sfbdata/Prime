<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;

/**
 * O executor das propostas da `atualizar_cliente` (`docs/specs/mcp-remoto-oauth.md`, 6.6 e 6.7), pelo contrato da
 * {@see ExecutorDeProposta}. A ação da proposta é a chave da {@see TabelaDeAbaEChave}; a ação fina mora em
 * `dados.operacao`:
 *
 *  - `cadastrar` / `cadastrar`: a lista de clientes ({@see AtualizarClienteLote});
 *  - `completar` ou `contato` / `trocar`: a troca de valor preenchido, com os campos vazios do mesmo pedido
 *    ({@see AtualizarClienteCadastro});
 *  - `vincular_a_pasta` / `desvincular`: tirar o cliente da pasta ({@see AtualizarClienteVinculos}).
 *
 * Reconferir e executar repetem a conferência da proposta inteira (permissão, pasta na lixeira, segredo, envio
 * automático, CPF que virou repetido, cliente ainda vinculado), sem gravar ao reconferir. Pessoa e escritório vêm só
 * da {@see ChamadaDoConector} da confirmação.
 */
final class AtualizarClienteConfirmacao implements ExecutorDeProposta
{
    /** A ação da tabela => a ação fina que ela aceita por proposta. */
    private const OPERACOES = [
        'cadastrar' => 'cadastrar',
        'completar' => 'trocar',
        'contato' => 'trocar',
        'vincular_a_pasta' => 'desvincular',
    ];

    public function __construct(
        private readonly AtualizarClienteLote $lote,
        private readonly AtualizarClienteCadastro $cadastro,
        private readonly AtualizarClienteVinculos $vinculos,
    ) {
    }

    public function ferramenta(): string
    {
        return AtualizarClienteFerramenta::NOME;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $operacao = self::operacao($acao, $dados);
        if ($operacao === 'cadastrar') {
            $this->lote->reconferir($dados, $chamada);

            return;
        }
        if ($operacao === 'trocar') {
            $this->cadastro->reconferirTroca($acao, $dados, $chamada);

            return;
        }
        $this->vinculos->reconferirDesvinculo($dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        return match (self::operacao($acao, $dados)) {
            'cadastrar' => $this->lote->executarConfirmada($dados, $chamada),
            'trocar' => $this->cadastro->executarTroca($acao, $dados, $chamada),
            default => $this->vinculos->executarDesvinculo($dados, $chamada),
        };
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws \LogicException proposta que esta ferramenta não gravaria
     */
    private static function operacao(string $acao, array $dados): string
    {
        $operacao = self::OPERACOES[$acao] ?? null;
        if ($operacao === null || ($dados['operacao'] ?? null) !== $operacao) {
            throw new \LogicException(sprintf('Proposta da atualizar_cliente fora da lista: %s.', $acao));
        }

        return $operacao;
    }
}
