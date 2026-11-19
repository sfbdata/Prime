<?php

declare(strict_types=1);

namespace App\Indicacao\Service;

use App\Indicacao\Repository\IndicacaoRepository;

/**
 * Leitura pública, para os outros domínios, de quantos colegas a pessoa trouxe pelo cadastro: indicações
 * `por_cadastro` cuja conta nova já confirmou o e-mail (spec `ia-liberada-por-indicacao.md`, seção 7). O Inteligencia
 * não lê as tabelas da Indicacao: pergunta aqui.
 */
// Não-final: permite substituição por mock nos testes de quem a consome.
class ColegasCadastradosPorIndicacao
{
    public function __construct(
        private readonly IndicacaoRepository $indicacoes,
    ) {
    }

    public function contar(int $indicadorId): int
    {
        return $this->indicacoes->contarCadastrosConfirmadosDoIndicador($indicadorId);
    }
}
