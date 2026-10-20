<?php

declare(strict_types=1);

namespace App\Comercial\Exception;

/** O pedido do Master não vale para o modelo ou a mensagem da campanha (a mensagem vai à tela como está). */
final class CampanhaRecusada extends \DomainException
{
}
