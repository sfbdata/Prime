<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\PedidoDeMarcas;
use App\Comercial\Repository\CampanhaMarcaRepository;
use Psr\Clock\ClockInterface;

/**
 * As marcas do Relatório (desenho 05 VS 21.10.26, L301, L304 e L704; spec `comercial-marcas.md`, regras 2 a 4): a
 * estrela da linha, os botões da seleção (Arquivar ou Desarquivar, Estrela, a lixeira) e os itens do menu do botão
 * direito. Cada Master marca só para si. "Remover" só esconde do relatório de quem removeu: nada da campanha nem dos
 * envios é apagado. Devolve quantas campanhas foram marcadas (id inexistente é ignorado).
 */
final class MarcarCampanhasDoRelatorioUseCase
{
    public function __construct(
        private readonly CampanhaMarcaRepository $marcas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(PedidoDeMarcas $pedido, int $masterId): int
    {
        return $this->marcas->marcar($pedido->ids, $masterId, $pedido->acao, $this->relogio->now());
    }
}
