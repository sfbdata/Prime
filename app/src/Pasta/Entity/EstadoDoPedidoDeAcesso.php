<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Estados do pedido de acesso a uma pasta com cadeado (spec pasta-cadeado K5). Só `Pendente` aceita
 * decisão; os outros três são finais.
 */
enum EstadoDoPedidoDeAcesso: string
{
    case Pendente           = 'pendente';
    case LiberadoVisualizar = 'liberado_visualizar';
    case LiberadoEditar     = 'liberado_editar';
    case Recusado           = 'recusado';

    public static function liberadoPara(NivelDeAcesso $nivel): self
    {
        return match ($nivel) {
            NivelDeAcesso::Visualizar => self::LiberadoVisualizar,
            NivelDeAcesso::Editar     => self::LiberadoEditar,
            NivelDeAcesso::Oculto     => throw new \InvalidArgumentException('Liberar para ocultar não existe: use recusar.'),
        };
    }
}
