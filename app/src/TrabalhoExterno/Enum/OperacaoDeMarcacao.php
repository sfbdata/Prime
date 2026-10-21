<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * As operações em lote do desenho (`lote()` e `tlLote()`). Nenhuma altera o item para os outros
 * participantes: valem só para quem marcou.
 */
enum OperacaoDeMarcacao: string
{
    case Lixeira = 'lixeira';
    case Restaurar = 'restaurar';
    case Definitivo = 'definitivo';
    case Lida = 'lida';
    case NaoLida = 'naolida';
    case Estrela = 'estrela';
    case SemEstrela = 'semestrela';
    case Importante = 'imp';
    case NaoImportante = 'naoimp';

    /** Complemento do aviso "N demanda(s)…" (mapa `T` de `lote()`). */
    public function complementoDaDemanda(): string
    {
        return match ($this) {
            self::Lixeira => ' movida(s) para a lixeira',
            self::Restaurar => ' restaurada(s)',
            self::Definitivo => ' excluída(s) da sua lista',
            self::Lida => ' marcada(s) como lida',
            self::NaoLida => ' marcada(s) como não lida',
            self::Estrela => ' com estrela',
            self::SemEstrela => ' sem estrela',
            self::Importante => ' marcada(s) como importante',
            self::NaoImportante => ' marcada(s) como não importante',
        };
    }

    /** Complemento do aviso "N item(ns)…" de vagas, currículos e candidaturas (mapa `T2` de `tlLote()`). */
    public function complementoDoItem(): string
    {
        return match ($this) {
            self::Lixeira => ' movido(s) para a lixeira',
            self::Restaurar => ' restaurado(s)',
            self::Definitivo => ' excluído(s) da sua lista',
            self::Lida => ' marcado(s) como visto',
            self::NaoLida => ' marcado(s) como não visto',
            self::Estrela => ' com estrela',
            self::SemEstrela => ' sem estrela',
            self::Importante => ' marcado(s) como importante',
            self::NaoImportante => ' marcado(s) como não importante',
        };
    }
}
