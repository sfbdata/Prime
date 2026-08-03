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
    /**
     * Anotação escrita à mão na linha do tempo (ajuste 2026-07): o que a equipe combinou, ouviu ou
     * decidiu e não cabe em nenhum evento estruturado. É registro, não rascunho — como todo evento
     * daqui, nasce append-only e não é editável nem apagável.
     */
    case Anotacao = 'anotacao';

    /**
     * Carimbo de qualificação do devedor, gravado em UM clique no painel da aba Responsáveis
     * (2026-07-27): recusa de pagamento, telefone inexistente, promessa de pagamento. A qualificação
     * em si vai no payload `dados` (`QualificacaoContato`); a `descricao` guarda o rótulo.
     *
     * Diferente da anotação, admite DESFAZER numa janela curta (5 min, só o autor, só a mais recente)
     * — o registro nasce de um clique único, sem formulário nem confirmação, e sem essa válvula um
     * engano viraria histórico permanente.
     */
    case QualificacaoContato = 'qualificacao_contato';

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
    case AcordoEditado = 'acordo_editado';
    case AcordoRompido = 'acordo_rompido';
    case AcordoCancelado = 'acordo_cancelado';
    case AcordoCumprido = 'acordo_cumprido';
    case PagamentoRegistrado = 'pagamento_registrado';
    case PagamentoCorrigido = 'pagamento_corrigido';
    case PagamentoExcluido = 'pagamento_excluido';
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
            self::AcordoEditado => 'Acordo editado',
            self::AcordoRompido => 'Acordo rompido',
            self::AcordoCancelado => 'Acordo cancelado',
            self::AcordoCumprido => 'Acordo cumprido',
            self::PagamentoRegistrado => 'Pagamento registrado',
            self::PagamentoCorrigido => 'Pagamento corrigido',
            self::PagamentoExcluido => 'Pagamento excluído',
            self::LiquidacaoRegistrada => 'Liquidação registrada',
            self::PessoaCobradaAlterada => 'Pessoa cobrada alterada',
            self::RevisaoVinculo => 'Revisão de vínculo',
            self::Judicializacao => 'Judicialização',
            self::VinculoPasta => 'Vínculo com pasta',
            self::Encerramento => 'Encerramento',
            self::Anotacao => 'Anotação',
            self::QualificacaoContato => 'Qualificação de contato',
        };
    }

    /**
     * O evento é TRABALHO DE COBRANÇA (falar com devedor, negociar, receber, encaminhar) e não
     * lançamento de cadastro/importação? (Central de Acompanhamento, spec §5.1.)
     *
     * O corte existe porque a Central rejeitou o `audit_log` justamente por uma importação inflar os
     * números — e a mesma distorção entrava pela fonte escolhida: no dev, 537 `obrigacao_criada` +
     * 121 `caso_aberto` de uma importação contra 3 contatos reais. Sem esta separação, quem só subiu
     * planilha aparece com "ação recente" sem ter cobrado ninguém.
     *
     * `match` SEM `default` de propósito: um tipo novo no enum quebra aqui (`UnhandledMatchError`) em
     * vez de escorregar silenciosamente para um dos lados. É a classificação que a spec §5.1 exige,
     * feita na hora de criar o tipo — não descoberta depois num relatório errado.
     *
     * O corte é por TIPO, não por origem: obrigação criada à mão continua fora, porque distinguir
     * importação de digitação exigiria marcar a origem no evento (escrita nova, fora da abordagem A).
     */
    public function ehTrabalhoDeCobranca(): bool
    {
        return match ($this) {
            self::ContatoRealizado,
            self::BoletoEnviado,
            self::NovoPrazo,
            self::Negociacao,
            self::AcordoCriado,
            self::AcordoEditado,
            self::AcordoRompido,
            self::AcordoCancelado,
            self::AcordoCumprido,
            self::PagamentoRegistrado,
            self::PagamentoCorrigido,
            self::LiquidacaoRegistrada,
            self::PessoaCobradaAlterada,
            self::Judicializacao,
            self::VinculoPasta,
            self::Encerramento,
            self::Anotacao,
            // Qualificar o devedor é trabalho de cobrança: sai de uma ligação ou de uma tentativa de
            // contato, não de importação de planilha. Conta na Central junto do resto.
            self::QualificacaoContato => true,

            self::CasoAberto,
            self::ObrigacaoCriada,
            self::ObrigacaoEditada,
            self::ObrigacaoExcluida,
            self::ValorAtualizadoReconhecido,
            // Apagar um recebimento lançado por engano é correção ADMINISTRATIVA, não uma das quatro
            // ações do corte (falar, negociar, receber, encaminhar) — fica fora pelo mesmo motivo que
            // `ObrigacaoExcluida`. Contar aqui faria quem só desfez o próprio erro aparecer com "ação
            // recente" sem ter cobrado ninguém, que é a distorção que a Central existe para evitar.
            self::PagamentoExcluido,
            self::RevisaoVinculo => false,
        };
    }

    /**
     * @return list<self>
     */
    public static function trabalhoDeCobranca(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->ehTrabalhoDeCobranca()));
    }

    /**
     * @return list<self>
     */
    public static function lancamentoDeCadastro(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => !$t->ehTrabalhoDeCobranca()));
    }

    /**
     * Valores crus dos tipos, para bind em consulta (`IN (:tipos)`).
     *
     * @param list<self> $tipos
     *
     * @return list<string>
     */
    public static function valoresDe(array $tipos): array
    {
        return array_map(static fn (self $t): string => $t->value, $tipos);
    }
}
