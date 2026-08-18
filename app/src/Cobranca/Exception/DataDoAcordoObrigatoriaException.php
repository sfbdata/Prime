<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Acordo lavrado NA TELA sem data (decisão do dono, 17/08).
 *
 * A regra do espelho — "não inventar o que a fonte não deu" — governa o que vem da CONTABILIDADE, e é
 * por isso que o IMPORTE grava acordo sem data. Aqui a fonte é a pessoa que está lavrando o acordo, e
 * ela sabe a data: afrouxar abriria porta para dado incompleto por descuido, sem ganho nenhum de
 * fidelidade.
 *
 * Existe porque `Acordo::setDataAcordo()` passou a aceitar `null` (a coluna virou anulável). Até 17/08
 * o próprio tipo recusava, e o `#[Assert\NotNull]` do input era a segunda linha de defesa; agora o
 * setter aceita calado, e sem esta guarda um input malformado criaria um acordo manual sem data que só
 * explodiria adiante, na materialização, com erro sem relação com a causa.
 */
final class DataDoAcordoObrigatoriaException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Acordo lavrado na tela exige a data do acordo — só a importação grava acordo sem data, '
            . 'quando a própria contabilidade não a informa.',
        );
    }
}
