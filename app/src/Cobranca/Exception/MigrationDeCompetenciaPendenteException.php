<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * O banco não tem a coluna `cobranca_obrigacao.competencia` (`Version20260730120000`).
 *
 * A importação **para antes de escrever**. Sem esta guarda o lote roda até o primeiro INSERT, estoura com
 * erro de driver e faz rollback: nada suja o banco, mas o operador recebe um traço de SQL que não diz o
 * que fazer. A chave (caso, NN, competência) é o que impede o importador de engolir boleto novo quando a
 * contábil reaproveita o Nosso Número — sem a coluna, não há importação segura a fazer.
 */
final class MigrationDeCompetenciaPendenteException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'O banco ainda não tem a coluna `cobranca_obrigacao.competencia`: rode a migration '
            . 'Version20260730120000 antes de importar. Sem ela o casamento do boleto seria feito só pelo '
            . 'Nosso Número, que a contábil reaproveita entre carteiras — nenhuma escrita foi feita.',
        );
    }
}
