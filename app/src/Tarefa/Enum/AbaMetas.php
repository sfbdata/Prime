<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

/**
 * As abas da tela "Minhas Metas" — cada uma é um PAPEL do usuário sobre a meta.
 *
 * Existe porque a tela nasceu unindo dois papéis numa lista só (`responsável OR criador`),
 * e quem delega via a própria fila de trabalho poluída pelo que tinha delegado: em produção
 * um usuário responsável por 4 metas via 88. Ver `docs/specs/minhas-metas-abas.md`.
 */
enum AbaMetas: string
{
    /** O que está com o usuário para fazer. */
    case RESPONSAVEL = 'responsavel';

    /** O que o usuário delegou (ou criou para si) — fila de acompanhamento de quem delega. */
    case CRIEI = 'criei';

    /** Marcação pessoal do usuário, invisível para os colegas. */
    case ACOMPANHANDO = 'acompanhando';

    /** A união dos três papéis — o comportamento antigo da tela, preservado como escolha. */
    case TODAS = 'todas';

    public static function padrao(): self
    {
        return self::RESPONSAVEL;
    }

    /**
     * Aba desconhecida cai no PADRÃO, nunca em TODAS: degradar para a lista mais ampla
     * devolveria exatamente a mistura que esta frente veio desfazer.
     */
    public static function deQueryString(?string $valor): self
    {
        return self::tryFrom((string) $valor) ?? self::padrao();
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::RESPONSAVEL  => 'Sou responsável',
            self::CRIEI        => 'Criei',
            self::ACOMPANHANDO => 'Em acompanhamento',
            self::TODAS        => 'Todas',
        };
    }

    /**
     * Nome da coluna "quem" na lista: na aba do responsável interessa quem delegou;
     * nas outras, quem está com a meta.
     */
    public function rotuloDaColunaQuem(): string
    {
        return match ($this) {
            self::RESPONSAVEL => 'Delegada por',
            default           => 'Responsável',
        };
    }

    /** A aba "Criei" agrupa por etapa da revisão; as demais, por urgência do prazo. */
    public function agrupaPorEtapaDaRevisao(): bool
    {
        return $this === self::CRIEI;
    }
}
