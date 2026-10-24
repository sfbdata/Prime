<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/**
 * Quem pediu para zerar ou desfazer não é o Master nem um primário ativo do escritório
 * (RegraDosPrimarios::podeGerirPrimarios). Vira 403 no controller; na tela essa pessoa vê
 * "Pedir para zerar".
 */
final class ZeramentoNaoPermitidoException extends \DomainException
{
}
