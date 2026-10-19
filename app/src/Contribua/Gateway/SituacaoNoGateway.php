<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * O que o gateway diz de uma assinatura, consultado na fonte. É a ÚNICA base para mudar o espaço de um
 * escritório: avisos (webhooks) nunca são confiados sozinhos, só disparam esta consulta.
 */
final readonly class SituacaoNoGateway
{
    public function __construct(
        public string $idDaAssinatura,
        public SituacaoDaAssinatura $situacao,
        /** Até quando o mês pago vale (fim do período pago); null enquanto nada foi pago. */
        public ?\DateTimeImmutable $pagaAte,
        /**
         * A referência do pedido (`BJ<conta>-<plano>-<id>`) ou, nos gateways em que o vínculo passa pelo
         * cliente (Asaas), a do escritório (`BJ<conta>`). Basta começar por `BJ<conta>`.
         */
        public ?string $referencia = null,
        /** O cliente no gateway, quando o vínculo com o escritório passa por ele (Asaas). */
        public ?string $idDoCliente = null,
        /** Valor da cobrança vigente, em centavos, para conferir com o plano. */
        public ?int $valorCentavos = null,
        /**
         * Só na resposta de `assinarComToken`: o banco pediu autenticação (3-D Secure) e o navegador precisa
         * concluí-la com este segredo (Stripe: `client_secret` da primeira fatura). O JS do gateway o recebe em
         * `concluirAcao()`. Null quando não há nada a fazer no navegador.
         */
        public ?string $acaoNoNavegador = null,
    ) {
    }
}
