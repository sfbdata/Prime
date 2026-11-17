<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * A pessoa não tem o papel que a regra pede para registrar a ocorrência ou decidir a avaliação (spec
 * docs/specs/voto-avaliacoes-do-conselho-1611.md, seção 3). Vira 403 no controller: o UseCase confere o papel mesmo com
 * a barreira de rota, porque a decisão é a única escrita do nível Visualizar.
 */
final class SemPapelNaAvaliacaoException extends \DomainException
{
}
