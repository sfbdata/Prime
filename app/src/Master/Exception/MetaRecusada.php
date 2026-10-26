<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao mexer nas Metas de divulgação do Master (desenho 05 Perfis 1.2 VS 25.10.26): número fora do intervalo,
 * estado desconhecido, cor fora das seis do menu, frequência inválida. O controller devolve 422 com a mensagem.
 */
final class MetaRecusada extends \DomainException
{
}
