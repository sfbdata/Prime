<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * A chave do envio de campanhas como a Central de envios mostra (pedido do Farlei M8.1, 23/11/2026): ligada ou não, de
 * onde vem o estado (`painel` ou `ambiente`, o padrão do servidor enquanto ninguém mexeu) e quantas campanhas estão
 * paradas esperando a religação.
 */
final readonly class EstadoDoEnvioDeCampanhas
{
    public function __construct(
        public bool $ligada,
        public string $origem,
        public int $esperando,
    ) {
    }

    /** @return array{ligada: bool, origem: string, esperando: int} */
    public function paraJson(): array
    {
        return ['ligada' => $this->ligada, 'origem' => $this->origem, 'esperando' => $this->esperando];
    }
}
