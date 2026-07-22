<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Desfecho de um contato de cobrança (ajuste 2026-07). O default operacional é "Não atendido" (o caso
 * típico da tentativa). Backed string — gravado no payload `dados` do EventoHistorico; apresentação via
 * `label()`. Sem persistência em coluna tipada → não há migração.
 *
 * `PrometeuPagar` NÃO é oferecido no formulário (ver `selecionaveis()`) — segue no enum só para ler
 * contatos antigos já gravados com `prometeu_pagar` (remover o case quebraria `from()` na exibição do
 * histórico legado).
 */
enum ResultadoContato: string
{
    case NaoAtendido = 'nao_atendido';
    case Atendido = 'atendido';
    case CaixaPostal = 'caixa_postal';
    case NumeroErrado = 'numero_errado';
    case PrometeuPagar = 'prometeu_pagar';
    case PediuRetorno = 'pediu_retorno';
    case InformouOutroNumero = 'informou_outro_numero';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::NaoAtendido => 'Não atendido',
            self::Atendido => 'Atendido',
            self::CaixaPostal => 'Caixa postal',
            self::NumeroErrado => 'Número errado',
            self::PrometeuPagar => 'Prometeu pagar',
            self::PediuRetorno => 'Pediu retorno',
            self::InformouOutroNumero => 'Informou outro número',
            self::Outro => 'Outro',
        };
    }

    /**
     * Opções selecionáveis no formulário de novo contato — todas menos `PrometeuPagar` (ajuste 2026-07,
     * ver spec `docs/specs/cobranca-contato-resultado-relato.md`). Ordem fixa exibida no `<select>`.
     *
     * @return list<self>
     */
    public static function selecionaveis(): array
    {
        return [
            self::NaoAtendido,
            self::Atendido,
            self::CaixaPostal,
            self::NumeroErrado,
            self::PediuRetorno,
            self::InformouOutroNumero,
            self::Outro,
        ];
    }
}
