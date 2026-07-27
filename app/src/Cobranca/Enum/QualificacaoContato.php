<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Qualificação do devedor registrada em UM clique no painel da aba Responsáveis (2026-07-27).
 *
 * É diferente do `ResultadoContato`: aquele descreve como TERMINOU uma tentativa de contato e vive
 * dentro do formulário "Registrar contato" (canal, data, relato). Este aqui é um carimbo rápido do
 * que a equipe descobriu sobre o devedor — grava direto no histórico, sem formulário, porque o valor
 * dele está em ser instantâneo durante a ligação.
 *
 * `PromessaPagamento` existe aqui de propósito, mesmo com `ResultadoContato::PrometeuPagar` tendo sido
 * TIRADO do formulário de contato: o dono do sistema pediu explicitamente o botão. São caminhos
 * distintos — um é desfecho de tentativa, o outro é qualificação do devedor.
 *
 * Backed string gravado no payload `dados` do EventoHistorico; nenhuma coluna tipada → sem migração.
 */
enum QualificacaoContato: string
{
    case RecusaPagamento = 'recusa_pagamento';
    case TelefoneInexistente = 'telefone_inexistente';
    case PromessaPagamento = 'promessa_pagamento';

    public function label(): string
    {
        return match ($this) {
            self::RecusaPagamento => 'Recusa de pagamento',
            self::TelefoneInexistente => 'Telefone inexistente',
            self::PromessaPagamento => 'Promessa de pagamento',
        };
    }

    /**
     * Ícone Bootstrap do botão. Fica no enum (e não no Twig) para os três botões e as linhas da
     * listinha lerem a MESMA fonte — rótulo e ícone não podem divergir entre painel e histórico.
     */
    public function icone(): string
    {
        return match ($this) {
            self::RecusaPagamento => 'bi-hand-thumbs-down',
            self::TelefoneInexistente => 'bi-telephone-x',
            self::PromessaPagamento => 'bi-hand-thumbs-up',
        };
    }

    /**
     * Ordem fixa exibida no painel, igual à da montagem enviada pelo dono: as duas negativas primeiro,
     * a positiva por último e destacada.
     *
     * @return list<self>
     */
    public static function doPainel(): array
    {
        return [
            self::RecusaPagamento,
            self::TelefoneInexistente,
            self::PromessaPagamento,
        ];
    }

    /**
     * Hidrata o valor cru vindo do payload de um evento já gravado. Devolve `null` em vez de lançar:
     * evento antigo (ou gravado por versão futura com um case a mais) não pode derrubar a leitura da
     * página inteira — a listinha simplesmente ignora a linha que não sabe interpretar.
     */
    public static function tentarDe(mixed $valor): ?self
    {
        return \is_string($valor) ? self::tryFrom($valor) : null;
    }
}
