<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\Entity\PublicacaoDjen;

/**
 * Uma publicação do Diário ligada à pasta, para leitura (hoje, pelo conector de I.A.). `texto` é o teor em texto
 * puro (tags fora, entidades decodificadas), ainda sem máscara nem corte: proteger e cortar é de quem entrega.
 */
final readonly class PublicacaoDaPastaOutput
{
    public function __construct(
        public int $id,
        public string $tribunal,
        public ?string $tipo,
        public ?string $numeroDoProcesso,
        public ?string $data,
        public bool $lida,
        public ?string $texto,
    ) {
    }

    public static function de(PublicacaoDjen $publicacao, ?string $texto): self
    {
        $numero = (string) ($publicacao->getNumeroProcessoComMascara() ?? '');

        return new self(
            (int) $publicacao->getId(),
            $publicacao->getSiglaTribunal(),
            $publicacao->getTipoDocumento() ?? $publicacao->getTipoComunicacao(),
            $numero !== '' ? $numero : $publicacao->getNumeroProcesso(),
            $publicacao->getDataDisponibilizacao()?->format('Y-m-d'),
            $publicacao->isLida(),
            $texto,
        );
    }
}
