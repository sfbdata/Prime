<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

/**
 * Tipo do processo apensado à pasta (P4f, desenho `pvVals.rels`, N:3141): o que o processo é em relação
 * ao principal. O valor é a chave gravada em `pasta_processo.tipo_apenso`; o rótulo é o texto exato do
 * desenho. Vínculo antigo sem tipo é lido como {@see self::Outro}.
 */
enum TipoDeApenso: string
{
    case Embargos              = 'embargos';
    case AgravoDeInstrumento   = 'agravo_de_instrumento';
    case CumprimentoDeSentenca = 'cumprimento_de_sentenca';
    case Execucao              = 'execucao';
    case Impugnacao            = 'impugnacao';
    case Recurso               = 'recurso';
    case Cautelar              = 'cautelar';
    case Outro                 = 'outro';

    public function rotulo(): string
    {
        return match ($this) {
            self::Embargos              => 'Embargos',
            self::AgravoDeInstrumento   => 'Agravo de instrumento',
            self::CumprimentoDeSentenca => 'Cumprimento de sentença',
            self::Execucao              => 'Execução',
            self::Impugnacao            => 'Impugnação',
            self::Recurso               => 'Recurso',
            self::Cautelar              => 'Cautelar',
            self::Outro                 => 'Outro',
        };
    }

    /**
     * As opções do select "Tipo de apenso", na ordem do desenho (Embargos primeiro, que é o padrão).
     *
     * @return array<string, string> valor => rótulo
     */
    public static function opcoes(): array
    {
        $opcoes = [];
        foreach (self::cases() as $tipo) {
            $opcoes[$tipo->value] = $tipo->rotulo();
        }

        return $opcoes;
    }
}
