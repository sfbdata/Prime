<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Um webhook já autenticado e traduzido. Não carrega decisão nenhuma: diz só QUAL assinatura (ou pedido)
 * mudou, e o BlueJus reconsulta (`GatewayDeAssinatura::consultar`) antes de mexer em qualquer coisa.
 */
final readonly class AvisoDoGateway
{
    public function __construct(
        /** Id do evento no gateway: a entrega é "pelo menos uma vez", e repetido é ignorado. */
        public string $idDoEvento,
        public string $tipo,
        public ?string $idDaAssinatura = null,
        public ?string $referencia = null,
        public ?string $idDoCliente = null,
    ) {
        if ($idDaAssinatura === null && $referencia === null && $idDoCliente === null) {
            throw new \LogicException('Aviso sem assinatura, referência nem cliente não serve para reconsultar nada.');
        }
    }
}
