<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\ConversaDeChat;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\Enum\ModeloDeIa;

/**
 * O que a preparação do envio entrega à transmissão: tudo já validado e gravado (a pergunta está no banco), faltando só
 * falar com o provedor. `primeiraResposta` diz se a conversa ainda não tinha resposta (gera o título automático).
 */
final readonly class RespostaPreparada
{
    public function __construct(
        public ConversaDeChat $conversa,
        public Tenant $tenant,
        public ModeloDeIa $modelo,
        public PedidoDeLinguagem $pedido,
        public string $pergunta,
        public bool $primeiraResposta,
    ) {
    }
}
