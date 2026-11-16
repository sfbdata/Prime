<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Contribua\Enum\SituacaoDoPedidoDeEspaco;

/** Uma linha do painel "Pedidos de espaço" do Master (desenho Contribua 14 e 15/11, N7). */
final readonly class LinhaDoPedidoDeEspacoView
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $conta,
        public ?string $usuario,
        public ?string $email,
        public int $gbDesejado,
        /** "até 20 GB", "até 50 GB" ou "mais de 50 GB" (o texto do desenho para 20, 50 e 100). */
        public string $estimativa,
        /** "uso X de Y GB" (sem limite: só o uso). */
        public string $usoTexto,
        public \DateTimeImmutable $pedidoEm,
        public ?\DateTimeImmutable $decididoEm,
        public ?string $motivo,
        public SituacaoDoPedidoDeEspaco $situacao,
    ) {
    }

    public static function estimativaDe(int $gb): string
    {
        return $gb >= 100 ? 'mais de 50 GB' : 'até ' . $gb . ' GB';
    }
}
