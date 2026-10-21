<?php

declare(strict_types=1);

namespace App\Cliente\Enum;

/**
 * As três chaves do "Envio automático" do cartão Avisar cliente (spec push-envio-automatico §2;
 * desenho `mailVals.autos`, dc N:5270), na ordem e com o rótulo e a dica exatos do desenho. O valor é
 * o que o POST `pasta_push_cliente_automatico` recebe no campo `chave` (e o nome da coluna).
 *
 * D1 (dono, 19/10/2026): as três nascem DESLIGADAS ({@see self::PADRAO}); o escritório liga por cliente.
 * Desvio do desenho aprovado, onde "Novas movimentações" nasce ligada.
 */
enum ChaveDoEnvioAutomatico: string
{
    case NovasMovimentacoes = 'novas_movimentacoes';
    case AudienciasIntimacoes = 'audiencias_intimacoes';
    case ResumoSemanal = 'resumo_semanal';

    /** O padrão de todas as chaves (D1): desligada. */
    public const PADRAO = false;

    public function rotulo(): string
    {
        return match ($this) {
            self::NovasMovimentacoes => 'Novas movimentações',
            self::AudienciasIntimacoes => 'Audiências e intimações',
            self::ResumoSemanal => 'Resumo semanal',
        };
    }

    public function dica(): string
    {
        return match ($this) {
            self::NovasMovimentacoes => 'Envia ao cliente um aviso com o texto oficial da movimentação, assim que ela chega',
            self::AudienciasIntimacoes => 'Avisa quando chegar intimação ou designação de audiência',
            self::ResumoSemanal => 'Toda segunda-feira, um resumo das movimentações da semana anterior',
        };
    }
}
