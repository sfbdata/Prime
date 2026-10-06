<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\RespostaDeLinguagem;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;

/**
 * A porta para o modelo de linguagem. O alias em `services.yaml` decide a implementação:
 * {@see ProvedorNaoConfigurado} em dev/prod enquanto o dono não escolhe o fornecedor (D1), e o
 * `ProvedorFalso` de `app/tests/` em `when@test`.
 *
 * REGRA DE OURO: nenhuma implementação devolve texto que não veio do modelo. Sem provedor é exceção,
 * nunca resposta simulada.
 */
interface ProvedorDeLinguagem
{
    /** 'nao_configurado' | 'anthropic' | 'openai' | … — vai para a coluna `provedor` da análise. */
    public function nome(): string;

    public function estaConfigurado(): bool;

    /**
     * @throws ProvedorIndisponivelException não configurado/desligado (sem retry)
     * @throws FalhaDoProvedorException      rede, 4xx/5xx, timeout, resposta inválida (`transitoria` decide o retry)
     */
    public function completar(PedidoDeLinguagem $pedido): RespostaDeLinguagem;
}
