<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;

/**
 * Uma movimentação nova no aviso do Push (sino e e-mail; spec push-avisar-quando-chegar §3): tipo,
 * data, órgão e um resumo de até 220 caracteres em texto puro. Nunca o teor inteiro: o e-mail sai
 * do sistema, e quem quer ler abre a pasta.
 */
final readonly class MovimentacaoDoAvisoOutput
{
    public const LIMITE_RESUMO = 220;

    public function __construct(
        public string $tipo,
        public ?string $data,
        public ?string $orgao,
        public ?string $resumo,
    ) {
    }

    public static function dePublicacao(PublicacaoDjen $publicacao): self
    {
        $tipo = trim((string) $publicacao->getTipoComunicacao());
        $orgao = trim((string) $publicacao->getNomeOrgao());

        return new self(
            $tipo !== '' ? $tipo : 'Movimentação',
            $publicacao->getDataDisponibilizacao()?->format('d/m/Y'),
            $orgao !== '' ? $orgao : ($publicacao->getSiglaTribunal() !== '' ? $publicacao->getSiglaTribunal() : null),
            PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), self::LIMITE_RESUMO),
        );
    }
}
