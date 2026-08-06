<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Romper/cancelar tentado sobre um Acordo cujas parcelas um OUTRO acordo vigente substituiu como dívida
 * original ("acordo sobre acordo", ajuste 9 §2.1). Deixar o acordo não-vigente nesse estado duplicaria a
 * dívida no saldo: as originais que ele substituiu voltam ao exigível E as parcelas do acordo novo
 * continuam nele.
 *
 * Até 08/2026 a INV-I bloqueava criar esse estado e isto só alcançava dado LEGADO. Desde a spec
 * `cobranca-acordo-assume-parcelas-do-anterior.md` o importador de acordos o cria quando a coluna F da
 * planilha prova a renegociação em cadeia — então este alarme passou a ser a proteção principal contra o
 * §2.1, e não uma sobra de acervo antigo. Erro de entrada: o gestor precisa desfazer o acordo novo primeiro.
 */
final class AcordoComParcelasRenegociadasException extends \DomainException
{
    public function __construct(int $acordoId, int $acordoRenegociadorId)
    {
        parent::__construct(sprintf(
            'Acordo %d não pode ser rompido ou cancelado: o acordo %d renegociou parcelas dele. '
            . 'Desfaça o acordo %d primeiro.',
            $acordoId,
            $acordoRenegociadorId,
            $acordoRenegociadorId,
        ));
    }
}
