<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * Quem não é a Administração (Master ou primário ativo do escritório) tentou designar, alterar ou remover a equipe de uma
 * assembleia. Vira 403 no controller.
 */
final class EquipeSoDaAdministracaoException extends \DomainException
{
}
