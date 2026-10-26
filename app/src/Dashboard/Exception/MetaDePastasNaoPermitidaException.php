<?php

declare(strict_types=1);

namespace App\Dashboard\Exception;

/**
 * Quem pediu para definir ou remover a meta de pastas do mês não é um primário ativo do escritório
 * (mesma régua do "Zerar": RegraDosPrimarios::podeGerirPrimarios). Vira 403 no controller; na tela essa
 * pessoa vê a meta, sem o botão de editar.
 */
final class MetaDePastasNaoPermitidaException extends \DomainException
{
}
