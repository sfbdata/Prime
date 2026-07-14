<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Tipo de evento na linha do tempo OPERACIONAL do Caso de Cobrança (SPEC §13).
 * É o histórico de domínio, visível ao usuário — distinto da auditoria técnica
 * (invariável 26). A lista cobre todos os eventos previstos na feature (PLAN §4.2);
 * cada etapa passa a gravar os tipos que lhe cabem.
 */
enum TipoEventoHistorico: string
{
    case CasoAberto = 'caso_aberto';
    case ObrigacaoCriada = 'obrigacao_criada';
    case ObrigacaoEditada = 'obrigacao_editada';
    case ObrigacaoExcluida = 'obrigacao_excluida';
    case ValorAtualizadoReconhecido = 'valor_atualizado_reconhecido';
    case ContatoRealizado = 'contato_realizado';
    case BoletoEnviado = 'boleto_enviado';
    case NovoPrazo = 'novo_prazo';
    case Negociacao = 'negociacao';
    case AcordoCriado = 'acordo_criado';
    case AcordoRompido = 'acordo_rompido';
    case AcordoCancelado = 'acordo_cancelado';
    case AcordoCumprido = 'acordo_cumprido';
    case PagamentoRegistrado = 'pagamento_registrado';
    case PagamentoCorrigido = 'pagamento_corrigido';
    case LiquidacaoRegistrada = 'liquidacao_registrada';
    case PessoaCobradaAlterada = 'pessoa_cobrada_alterada';
    /** Legado: a feature "Revisão de pessoa cobrada" foi removida; este caso permanece só para
     *  hidratar eventos históricos já gravados (`revisao_vinculo`). Nenhum código novo o cria. */
    case RevisaoVinculo = 'revisao_vinculo';
    case Judicializacao = 'judicializacao';
    case VinculoPasta = 'vinculo_pasta';
    case Encerramento = 'encerramento';

    public function label(): string
    {
        return match ($this) {
            self::CasoAberto => 'Caso aberto',
            self::ObrigacaoCriada => 'Obrigação criada',
            self::ObrigacaoEditada => 'Obrigação editada',
            self::ObrigacaoExcluida => 'Obrigação excluída',
            self::ValorAtualizadoReconhecido => 'Valor atualizado reconhecido',
            self::ContatoRealizado => 'Contato realizado',
            self::BoletoEnviado => 'Boleto/valor atualizado enviado',
            self::NovoPrazo => 'Novo prazo informado',
            self::Negociacao => 'Negociação',
            self::AcordoCriado => 'Acordo criado',
            self::AcordoRompido => 'Acordo rompido',
            self::AcordoCancelado => 'Acordo cancelado',
            self::AcordoCumprido => 'Acordo cumprido',
            self::PagamentoRegistrado => 'Pagamento registrado',
            self::PagamentoCorrigido => 'Pagamento corrigido',
            self::LiquidacaoRegistrada => 'Liquidação registrada',
            self::PessoaCobradaAlterada => 'Pessoa cobrada alterada',
            self::RevisaoVinculo => 'Revisão de vínculo',
            self::Judicializacao => 'Judicialização',
            self::VinculoPasta => 'Vínculo com pasta',
            self::Encerramento => 'Encerramento',
        };
    }
}
