<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Periodicidade do parcelamento de um acordo (Ajuste 7): a cadência dos vencimentos gerados a partir
 * da data da 1ª parcela. Só SEMEIA a sequência — os vencimentos gerados são editáveis um a um depois.
 */
enum Periodicidade: string
{
    case Mensal = 'mensal';
    case Quinzenal = 'quinzenal';
    case Semanal = 'semanal';

    public function label(): string
    {
        return match ($this) {
            self::Mensal => 'Mensal',
            self::Quinzenal => 'Quinzenal',
            self::Semanal => 'Semanal',
        };
    }

    /**
     * Vencimento da parcela a `$passos` intervalos da 1ª (passos = k-1; a 1ª parcela usa passos = 0).
     * Semanal = +7 dias/passo; Quinzenal = +14 dias/passo. Mensal preserva o dia do mês da 1ª parcela,
     * com clamp ao último dia do mês-alvo (nunca `modify('+1 month')` cru, que estoura 31/01 → 03/03).
     */
    public function proximoVencimento(\DateTimeImmutable $primeira, int $passos): \DateTimeImmutable
    {
        if ($passos <= 0) {
            return $primeira;
        }

        return match ($this) {
            self::Semanal => $primeira->modify(sprintf('+%d days', 7 * $passos)),
            self::Quinzenal => $primeira->modify(sprintf('+%d days', 14 * $passos)),
            self::Mensal => $this->somarMeses($primeira, $passos),
        };
    }

    private function somarMeses(\DateTimeImmutable $primeira, int $passos): \DateTimeImmutable
    {
        $diaOriginal = (int) $primeira->format('j');
        // Zera o dia (dia 1) antes de somar meses para nunca transbordar de mês; a hora é preservada.
        $primeiroDoMesAlvo = $primeira->modify('first day of this month')->modify(sprintf('+%d months', $passos));
        $dia = min($diaOriginal, (int) $primeiroDoMesAlvo->format('t'));

        return $primeiroDoMesAlvo->setDate(
            (int) $primeiroDoMesAlvo->format('Y'),
            (int) $primeiroDoMesAlvo->format('n'),
            $dia,
        );
    }
}
