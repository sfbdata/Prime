<?php

declare(strict_types=1);

namespace App\ChatIa\Enum;

/**
 * O período da consulta de prazos e metas pelo chat (spec `docs/specs/chat-ia-prazos-e-metas.md`). Os valores são contrato com
 * o modelo (o `enum` do esquema da ferramenta). Cada um usa um filtro `prazo` que a tela Minhas Metas JÁ tem: a consulta nunca
 * inventa recorte próprio de visibilidade.
 */
enum PeriodoDaConsulta: string
{
    case Hoje = 'hoje';
    case Proximos7Dias = 'proximos_7_dias';
    case Atrasadas = 'atrasadas';

    /** O valor do filtro `prazo` da barra da tela (`TarefaRepository::aplicarFiltrosDaTela`). "Hoje" é o "Vencem em 7 dias" recortado no dia. */
    public function filtroDaTela(): string
    {
        return match ($this) {
            self::Atrasadas => 'vencidas',
            self::Hoje, self::Proximos7Dias => 'proximas',
        };
    }

    /** Como o período aparece no cabeçalho do bloco que vai ao modelo. */
    public function rotulo(): string
    {
        return match ($this) {
            self::Hoje => 'vencem hoje',
            self::Proximos7Dias => 'vencem de hoje até daqui a 7 dias',
            self::Atrasadas => 'atrasadas (prazo já passou)',
        };
    }
}
