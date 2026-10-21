<?php

declare(strict_types=1);

namespace App\Master\Exception;

/**
 * Recusa ao editar a sede ou registrar uma divulgação numa seccional da OAB (spec master-seccionais-oab):
 * tamanho acima do limite, site fora do https://, tipo desconhecido, data inválida ou no futuro. O controller
 * devolve 422 com a mensagem como está.
 */
final class SeccionalRecusada extends \DomainException
{
}
