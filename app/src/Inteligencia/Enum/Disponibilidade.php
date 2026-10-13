<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Por que a BlueJus IA está (ou não) ao alcance de UM usuário em UM escritório, na ordem em que a
 * {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia} decide: plataforma → escritório →
 * permissão → cota. A UI mostra a `mensagem()` do motivo em vez de um botão que finge funcionar.
 */
enum Disponibilidade: string
{
    case Disponivel = 'disponivel';
    case NaoConfiguradaNaPlataforma = 'nao_configurada_na_plataforma';
    case DesligadaNoEscritorio = 'desligada_no_escritorio';
    case SemPermissao = 'sem_permissao';
    /** Cota DIÁRIA estourada (o nome é anterior à separação dia × mês; o valor é contrato com o JS). */
    case LimiteAtingido = 'limite_atingido';
    case LimiteMensalAtingido = 'limite_mensal_atingido';

    public function estaDisponivel(): bool
    {
        return $this === self::Disponivel;
    }

    /** Texto que a tela mostra no lugar do botão (spec §3.6). */
    public function mensagem(): string
    {
        return match ($this) {
            self::Disponivel => 'BlueJus IA disponível',
            self::NaoConfiguradaNaPlataforma => 'IA não configurada nesta instalação',
            self::DesligadaNoEscritorio => 'BlueJus IA desligada neste escritório. Peça ao administrador',
            self::SemPermissao => 'Sem permissão para usar a BlueJus IA',
            self::LimiteAtingido => 'Limite diário de análises atingido',
            self::LimiteMensalAtingido => 'Limite mensal de análises atingido',
        };
    }

    /** Cota do escritório (dia ou mês) estourada: a recusa é 429, não 409. */
    public function ehLimiteDeCota(): bool
    {
        return $this === self::LimiteAtingido || $this === self::LimiteMensalAtingido;
    }

    /**
     * Código do campo `motivo` na recusa JSON. O JS da pasta (`pasta-ia-push.js`,
     * `pasta-ia-agentes.js`) trava o botão quando o motivo está na lista dele, que só conhece
     * `limite_atingido`: as duas cotas respondem com esse código e a `mensagem()` diz qual delas foi.
     */
    public function codigoDaRecusa(): string
    {
        return $this->ehLimiteDeCota() ? self::LimiteAtingido->value : $this->value;
    }
}
