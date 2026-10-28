<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\UsoDaTransmissao;
use App\Inteligencia\Exception\FalhaDoProvedorException;
use App\Inteligencia\Exception\ProvedorIndisponivelException;

/**
 * A porta para o modelo de linguagem em FLUXO (Chat I.A em página inteira, `docs/specs/chat-pagina-inteira.md`
 * decisão 3): em vez de esperar a resposta inteira, rende os trechos de texto conforme o modelo os produz.
 *
 * Implementações: {@see ProvedorAnthropic} (SSE da API de Mensagens) e o `ProvedorFalso` de `app/tests/`. A mesma
 * regra de ouro da {@see ProvedorDeLinguagem}: nada de texto que não veio do modelo.
 *
 * O gerador RENDE strings (trechos de texto, na ordem) e DEVOLVE (`getReturn()`) o {@see UsoDaTransmissao}. Quem
 * para de consumir antes do fim não tem o retorno: o consumo real fica desconhecido.
 */
interface ProvedorDeLinguagemEmFluxo
{
    /**
     * @return \Generator<int, string, mixed, UsoDaTransmissao>
     *
     * @throws ProvedorIndisponivelException não configurado/desligado (sem retry)
     * @throws FalhaDoProvedorException      rede, 4xx/5xx, erro dentro do fluxo (`transitoria` decide o retry)
     */
    public function transmitir(PedidoDeLinguagem $pedido): \Generator;
}
