<?php

declare(strict_types=1);

namespace App\Shared\Prazo;

/**
 * A regra de prazo única do sistema (Guia visual, "Regra de prazo igual em todas as telas"), nascida no
 * cartão "Próximos prazos" da Pasta (desenho 1.2.3):
 *
 *  - a conta é em DIAS DE CALENDÁRIO, de hoje até o dia do prazo (a hora não conta: comparar "agora" com um
 *    prazo gravado à meia-noite faria "vence hoje" virar "vencido" a partir das 00:01);
 *  - texto: "Vencido há N dias", "Hoje", "Faltam N dias";
 *  - tom: vencido ou até 3 dias é urgente (vermelho), até 7 é próximo (âmbar), acima disso é tranquilo;
 *  - "prazo próximo" é de hoje até daqui a 7 dias, e "vencido" é antes de hoje: as contagens do Dashboard e a
 *    lista "Metas da equipe" usam esses mesmos limites.
 */
final class RegraDoPrazo
{
    public const TOM_URGENTE   = 'urgente';
    public const TOM_PROXIMO   = 'proximo';
    public const TOM_TRANQUILO = 'tranquilo';

    public const DIAS_URGENTE = 3;
    public const DIAS_PROXIMO = 7;

    private function __construct()
    {
    }

    /** Dias de calendário de $hoje até o prazo; negativo quando venceu. */
    public static function diasAte(\DateTimeImmutable $prazo, ?\DateTimeImmutable $hoje = null): int
    {
        $inicio = self::inicioDoDia($hoje ?? new \DateTimeImmutable('today'));

        return (int) $inicio->diff($prazo->setTime(0, 0))->format('%r%a');
    }

    public static function selo(int $dias): string
    {
        if ($dias < 0) {
            $atraso = abs($dias);

            return $atraso === 1 ? 'Vencido há 1 dia' : 'Vencido há ' . $atraso . ' dias';
        }

        if ($dias === 0) {
            return 'Hoje';
        }

        return $dias === 1 ? 'Faltam 1 dia' : 'Faltam ' . $dias . ' dias';
    }

    /** Vencido cai no urgente por ser o caso mais grave dos três. */
    public static function tom(int $dias): string
    {
        if ($dias <= self::DIAS_URGENTE) {
            return self::TOM_URGENTE;
        }

        if ($dias <= self::DIAS_PROXIMO) {
            return self::TOM_PROXIMO;
        }

        return self::TOM_TRANQUILO;
    }

    /** Começo do dia da referência: prazo antes disso está vencido. */
    public static function inicioDoDia(\DateTimeImmutable $referencia): \DateTimeImmutable
    {
        return $referencia->setTime(0, 0);
    }

    /** Fim (exclusivo) da janela "prazo próximo": o começo do 8º dia depois da referência. */
    public static function fimDoPrazoProximo(\DateTimeImmutable $referencia): \DateTimeImmutable
    {
        return self::inicioDoDia($referencia)->modify('+' . (self::DIAS_PROXIMO + 1) . ' days');
    }
}
