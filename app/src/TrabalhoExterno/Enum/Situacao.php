<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Situação de uma demanda de trabalho externo. Rótulos, cores e ícones são os do desenho
 * (`06 - Trabalho Externo 1.0`, `Component.ST`). "Pagamento registrado" não é situação: é o marco
 * `pagoEm` da demanda.
 */
enum Situacao: string
{
    case Rascunho = 'rascunho';
    case Nova = 'nova';
    case Analise = 'analise';
    case Info = 'info';
    case Proposta = 'proposta';
    case Aceita = 'aceita';
    case Execucao = 'execucao';
    case Validacao = 'validacao';
    case Concluida = 'concluida';
    case Arquivada = 'arquivada';
    case Recusada = 'recusada';
    case Cancelada = 'cancelada';

    /** Marco do histórico que não é situação (registro de pagamento). */
    public const MARCO_PAGO = 'pago';

    /** Marco do histórico (não é situação): o Master ajustou o valor do pagamento. */
    public const MARCO_VALOR = 'valor';

    public function rotulo(): string
    {
        return match ($this) {
            self::Rascunho => 'Rascunho',
            self::Nova => 'Nova solicitação',
            self::Analise => 'Em análise',
            self::Info => 'Aguardando informações',
            self::Proposta => 'Aguardando aceite',
            self::Aceita => 'Aceita',
            self::Execucao => 'Em execução',
            self::Validacao => 'Aguardando validação',
            self::Concluida => 'Concluída',
            self::Arquivada => 'Arquivada',
            self::Recusada => 'Recusada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function cor(): string
    {
        return match ($this) {
            self::Rascunho, self::Arquivada => '#6b8494',
            self::Nova => '#0a6fa0',
            self::Analise, self::Validacao => '#7a5200',
            self::Info => '#9a5b00',
            self::Proposta => '#5b3f9e',
            self::Aceita, self::Concluida => '#1e7b45',
            self::Execucao => '#0b5f86',
            self::Recusada, self::Cancelada => '#a3232b',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Rascunho => 'bi-pencil',
            self::Nova => 'bi-inbox',
            self::Analise => 'bi-search',
            self::Info => 'bi-question-circle',
            self::Proposta => 'bi-file-earmark-text',
            self::Aceita => 'bi-check2-circle',
            self::Execucao => 'bi-briefcase',
            self::Validacao => 'bi-hourglass-split',
            self::Concluida => 'bi-check2-all',
            self::Arquivada => 'bi-archive',
            self::Recusada => 'bi-x-circle',
            self::Cancelada => 'bi-slash-circle',
        };
    }

    /** Fechadas não contam prazo e não entram em "Abertas" (`Component.FECHADAS`). */
    public function ehFechada(): bool
    {
        return in_array($this, [self::Concluida, self::Arquivada, self::Recusada, self::Cancelada, self::Rascunho], true);
    }

    /** Situações em que o convidado enxerga a demanda e em que cabe registrar pagamento. */
    public function ehDeExecucao(): bool
    {
        return in_array($this, [self::Aceita, self::Execucao, self::Validacao, self::Concluida], true);
    }
}
