<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\RespostaPreparada;
use App\Inteligencia\DTO\ChamadaDeFerramenta;
use App\Inteligencia\DTO\PedidoDeLinguagem;

/**
 * As ferramentas de CONSULTA do chat (só leitura), vistas pela transmissão da resposta: quais chamadas do modelo são consulta
 * (e não proposta de cadastro) e o pedido da SEGUNDA chamada, já com o resultado. Implementação:
 * {@see ConsultaDePrazosNoChat} (spec `docs/specs/chat-ia-prazos-e-metas.md`).
 */
interface ConsultasDoChat
{
    public function ehConsulta(ChamadaDeFerramenta $chamada): bool;

    /**
     * Roda as consultas pedidas, para a pessoa e o escritório da conversa, e devolve o pedido da segunda chamada: o mesmo
     * pedido, com o resultado em bloco delimitado antes da pergunta e SEM ferramentas (não há laço). Nulo = nenhuma das
     * chamadas é consulta. Nunca grava nada.
     *
     * @param list<ChamadaDeFerramenta> $chamadas as chamadas de ferramenta da primeira resposta
     */
    public function pedidoComResultado(RespostaPreparada $preparada, array $chamadas): ?PedidoDeLinguagem;
}
