<?php

declare(strict_types=1);

namespace App\Contribua\Event;

/**
 * Um escritório pediu mais espaço ("Quero +N GB"). A automação "Pedido de mais espaço" do desenho 05
 * (frente "Comercial e e-mails") ouve este evento e confirma ao cliente "Recebemos seu pedido de mais
 * espaço". O aviso ao Master continua com o Contribua. Despachado depois do sucesso da requisição.
 */
final readonly class EspacoFoiPedido
{
    public function __construct(
        public int $tenantId,
        public string $codigoDoPlano,
        public ?int $pedidoPorUserId,
    ) {
    }
}
