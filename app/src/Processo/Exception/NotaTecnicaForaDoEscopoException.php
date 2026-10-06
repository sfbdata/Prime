<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * Lançada quando o alvo da nota não fecha: processo de outro escritório, publicação de outro
 * escritório ou publicação que não é daquele processo. O controller já barra tudo isso antes
 * (busca tenant-safe + 404); aqui é a defesa em profundidade do UseCase, para a regra não
 * depender de quem o chama.
 */
final class NotaTecnicaForaDoEscopoException extends \DomainException
{
}
