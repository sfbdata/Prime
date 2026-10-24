<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Por que a BlueJus IA está (ou não) ao alcance de UM usuário em UM escritório, na ordem em que a
 * {@see \App\Inteligencia\Service\DisponibilidadeDeInteligencia} decide: plataforma → liberação do Master →
 * escritório → permissão → cota. A UI mostra a `mensagem()` do motivo em vez de um botão que finge funcionar.
 */
enum Disponibilidade: string
{
    case Disponivel = 'disponivel';
    case NaoConfiguradaNaPlataforma = 'nao_configurada_na_plataforma';
    /** O Master ainda não liberou (ou bloqueou) a IA para o escritório (spec ia-anthropic-ligar §10.1). */
    case NaoLiberadaPeloMaster = 'nao_liberada_pelo_master';
    case DesligadaNoEscritorio = 'desligada_no_escritorio';
    case SemPermissao = 'sem_permissao';
    /** Cota DIÁRIA estourada (o nome é anterior à separação dia × mês; o valor é contrato com o JS). */
    case LimiteAtingido = 'limite_atingido';
    case LimiteMensalAtingido = 'limite_mensal_atingido';
    /**
     * O gasto estimado do mês em US$ chegou ao orçamento do escritório (modos `incluso` e `adicional`; spec
     * ia-anthropic-ligar §10.1, PR C). Em `limite_gratis` o orçamento é a parte grátis e não trava.
     */
    case OrcamentoAtingido = 'orcamento_atingido';

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
            self::NaoLiberadaPeloMaster => 'A BlueJus IA ainda não foi liberada para este escritório',
            self::DesligadaNoEscritorio => 'BlueJus IA desligada neste escritório. Peça ao administrador',
            self::SemPermissao => 'Sem permissão para usar a BlueJus IA',
            self::LimiteAtingido => 'Limite diário de análises atingido',
            self::LimiteMensalAtingido => 'Limite mensal de análises atingido',
            self::OrcamentoAtingido => 'Orçamento mensal da BlueJus IA atingido; volta no dia 1º',
        };
    }

    /** Cota do escritório (dia, mês ou orçamento em US$) estourada: a recusa é 429, não 409. */
    public function ehLimiteDeCota(): bool
    {
        return $this === self::LimiteAtingido || $this === self::LimiteMensalAtingido || $this === self::OrcamentoAtingido;
    }

    /**
     * Código do campo `motivo` na recusa JSON. O JS da pasta (`pasta-ia-push.js`,
     * `pasta-ia-agentes.js`) trava o botão quando o motivo está na lista dele, que só conhece
     * `limite_atingido`: as cotas (dia, mês e orçamento) respondem com esse código e a `mensagem()` diz qual delas foi.
     * Pelo mesmo motivo a falta de liberação do Master responde `desligada_no_escritorio` (o JS trava o botão
     * igual); a `mensagem()` diz que é a liberação que falta.
     */
    public function codigoDaRecusa(): string
    {
        return match (true) {
            $this->ehLimiteDeCota() => self::LimiteAtingido->value,
            $this === self::NaoLiberadaPeloMaster => self::DesligadaNoEscritorio->value,
            default => $this->value,
        };
    }
}
