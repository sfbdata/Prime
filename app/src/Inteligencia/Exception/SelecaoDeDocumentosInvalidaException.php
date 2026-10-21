<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A seleção do "Índice inteligente" da aba Documentos não cabe na regra (spec
 * inteligencia-drawer-da-pasta, F4): nenhum documento marcado, ou um id que não está na lista que a
 * aba mostra para quem pede (de outra pasta, de outro escritório, oculto pelas abas ou fora dos mais
 * recentes). O controller responde 422 com a mensagem; nada é persistido nem conta no limitador.
 *
 * A mensagem não diz qual id falhou nem por quê: não confirma que um documento de outra pasta existe.
 */
final class SelecaoDeDocumentosInvalidaException extends \DomainException
{
    public const MOTIVO = 'selecao_invalida';

    public static function vazia(): self
    {
        return new self('Marque ao menos um documento para montar o índice.');
    }

    public static function foraDaLista(): self
    {
        return new self('Um dos documentos marcados não está mais na lista desta pasta. Recarregue a página e marque de novo.');
    }

    public function motivo(): string
    {
        return self::MOTIVO;
    }
}
