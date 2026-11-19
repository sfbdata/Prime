<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * Lançada quando o processo a excluir não é do escritório informado. O controller já barra isso antes (busca
 * tenant-safe + 404); aqui é a defesa em profundidade do UseCase, para a regra não depender de quem o chama.
 */
final class ProcessoForaDoEscopoException extends \DomainException
{
}
