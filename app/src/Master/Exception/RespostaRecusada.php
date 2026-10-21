<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao responder pela Central de Notificações (spec master-central-de-notificacoes.md, D7 e D6): alerta, texto
 * vazio ou ninguém a quem responder. O controller devolve 422 com a mensagem como está.
 */
final class RespostaRecusada extends \DomainException
{
}
