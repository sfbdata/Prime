<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A pasta recebida de outra conta não abre para quem pede (spec acesso-restrito-e-compartilhar §4.5.1): convite que não
 * é dele, não aceito, vencido, pasta excluída ou trancada, conta de origem excluída, quem republicou já não poderia
 * republicar, ou quem pede tem vínculo com a conta da pasta. O controller responde 404 sem dizer qual foi.
 */
final class PastaRecebidaNaoEncontradaException extends \DomainException
{
}
