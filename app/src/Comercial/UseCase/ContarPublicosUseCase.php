<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\DTO\ContagemDosPublicos;
use App\Comercial\DTO\PedidoDePublico;

/**
 * Os números da coluna "2 · PARA QUEM" e da linha de destinatários (dc L.237, L.252; spec §8.5), pedidos pela tela a
 * cada troca de público, de arquivo ou de e-mails digitados. Só lê: nada é enviado nem gravado. O primeiro destinatário
 * volta como o exemplo da prévia (dc L.537).
 */
final class ContarPublicosUseCase
{
    public function __construct(private readonly PublicosDaCampanha $publicos)
    {
    }

    public function executar(PedidoDePublico $pedido): ContagemDosPublicos
    {
        $resolvido = $this->publicos->resolver($pedido->publico, $pedido->informados);

        return new ContagemDosPublicos(
            $this->publicos->tamanhos($pedido->publico, $pedido->informados),
            $resolvido->total(),
            $resolvido->descadastrados,
            $resolvido->semAutorizacao,
            $resolvido->primeiro(),
        );
    }
}
