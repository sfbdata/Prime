<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Tipo de alerta operacional do Caso de Cobrança (SPEC §14, invariável 28: o sistema alerta, o
 * humano decide). Alerta é DERIVADO de fatos do caso (read-only, sem persistir, sem mudar estado) —
 * distinto da Próxima Ação, que é uma decisão manual do gestor. Cada caso pode acumular vários
 * alertas simultâneos, um por condição verdadeira.
 */
enum TipoAlerta: string
{
    case ObrigacaoVencida = 'obrigacao_vencida';
    case ParcelaAcordoVencida = 'parcela_acordo_vencida';
    case AcaoAtrasada = 'acao_atrasada';
    case ProntoParaEncerrar = 'pronto_para_encerrar';

    public function label(): string
    {
        return match ($this) {
            self::ObrigacaoVencida => 'Obrigação exigível vencida',
            self::ParcelaAcordoVencida => 'Parcela de acordo vencida',
            self::AcaoAtrasada => 'Próxima ação atrasada',
            self::ProntoParaEncerrar => 'Pronto para encerrar',
        };
    }

    /** Classe Bootstrap `text-bg-*` para o badge do alerta (apresentação, Etapa 8). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::ObrigacaoVencida => 'text-bg-danger',
            self::ParcelaAcordoVencida => 'text-bg-danger',
            self::AcaoAtrasada => 'text-bg-warning',
            self::ProntoParaEncerrar => 'text-bg-success',
        };
    }

    /** Ícone Bootstrap Icons para o alerta (apresentação, Etapa 8). */
    public function icone(): string
    {
        return match ($this) {
            self::ObrigacaoVencida => 'bi-exclamation-triangle-fill',
            self::ParcelaAcordoVencida => 'bi-exclamation-triangle-fill',
            self::AcaoAtrasada => 'bi-clock-history',
            self::ProntoParaEncerrar => 'bi-check-circle-fill',
        };
    }
}
