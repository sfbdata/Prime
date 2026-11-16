<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use App\Contribua\Enum\OrigemDaLiberacao;

/**
 * O pedido de espaço como a tela do escritório o mostra: nenhum, em análise (com GB e data) ou opções liberadas (com a
 * origem). A liberação (Master ou uso em 97%) vale mais que um pedido pendente.
 */
final readonly class PedidoDeEspacoDoEscritorioView
{
    public const NENHUM = 'nenhum';
    public const PENDENTE = 'pendente';
    public const LIBERADO = 'liberado';

    public function __construct(
        /** `nenhum`, `pendente` ou `liberado`. */
        public string $estado,
        public ?int $gbDesejado = null,
        public ?\DateTimeImmutable $pedidoEm = null,
        public ?OrigemDaLiberacao $liberadaPor = null,
    ) {
    }

    public function pendente(): bool
    {
        return $this->estado === self::PENDENTE;
    }

    public function liberado(): bool
    {
        return $this->estado === self::LIBERADO;
    }
}
