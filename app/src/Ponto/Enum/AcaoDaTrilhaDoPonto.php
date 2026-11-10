<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

/**
 * Os eventos que entram na trilha de auditoria do Ponto (spec `ponto-desenho-0811.md`, E9). O código é o que a coluna
 * `acao` guarda (e o selo cobre), então um código gravado nunca muda; o rótulo é o texto da coluna "Ação" da aba
 * Auditoria e pode mudar à vontade.
 *
 * Fora da trilha, no `audit_log` como sempre: sede, feriado, limites, prazos, modo facial, foto e a chave Global do
 * Master. A chave Escritório de uma função (E7) entra como "Configuração do escritório". Os ajustes de batida (E10)
 * entram como inclusão, ajuste, desconsideração e ajuste desfeito; a inclusão do esquecimento abonado continua como
 * "Batida do esquecimento abonado".
 */
enum AcaoDaTrilhaDoPonto: string
{
    case BatidaRegistrada      = 'batida_registrada';
    case BatidaDoEsquecimento  = 'batida_do_esquecimento';
    case JustificativaEnviada  = 'justificativa_enviada';
    case JustificativaEditada  = 'justificativa_editada';
    case JustificativaExcluida = 'justificativa_excluida';
    case JustificativaAbonada  = 'justificativa_abonada';
    case JustificativaRecusada = 'justificativa_recusada';
    case JustificativaReaberta = 'justificativa_reaberta';
    case AbonoEmLote           = 'abono_em_lote';
    case JustificativaLancada  = 'justificativa_lancada';
    case EscalaAlterada        = 'escala_alterada';
    case ConfiguracaoDoEscritorio = 'configuracao_do_escritorio';
    case Sincronizacao         = 'sincronizacao';
    case BatidaIncluida        = 'batida_incluida';
    case BatidaAjustada        = 'batida_ajustada';
    case BatidaDesconsiderada  = 'batida_desconsiderada';
    case AjusteDesfeito        = 'ajuste_desfeito';

    public function rotulo(): string
    {
        return match ($this) {
            self::BatidaRegistrada      => 'Batida registrada',
            self::BatidaDoEsquecimento  => 'Batida do esquecimento abonado',
            self::JustificativaEnviada  => 'Justificativa enviada',
            self::JustificativaEditada  => 'Justificativa editada',
            self::JustificativaExcluida => 'Justificativa excluída',
            self::JustificativaAbonada  => 'Justificativa abonada',
            self::JustificativaRecusada => 'Justificativa recusada',
            self::JustificativaReaberta => 'Justificativa voltou para análise',
            self::AbonoEmLote           => 'Abono em lote',
            self::JustificativaLancada  => 'Justificativa lançada já abonada',
            self::EscalaAlterada        => 'Escala alterada',
            self::ConfiguracaoDoEscritorio => 'Configuração do escritório',
            self::Sincronizacao         => 'Sincronização',
            self::BatidaIncluida        => 'Batida incluída',
            self::BatidaAjustada        => 'Batida ajustada',
            self::BatidaDesconsiderada  => 'Batida desconsiderada',
            self::AjusteDesfeito        => 'Ajuste desfeito',
        };
    }

    /** O rótulo de um código gravado; um código que esta versão não conhece aparece como está. */
    public static function rotuloDe(string $codigo): string
    {
        return self::tryFrom($codigo)?->rotulo() ?? $codigo;
    }
}
