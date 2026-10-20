<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;

/**
 * Uma movimentação como a "Situação atual" do Push a lê (spec push-painel-monitoramento §3): a data
 * de disponibilização, o tipo da comunicação e o teor já como TEXTO puro (sem HTML). O desenho lê
 * `m.data`, `m.tipo` e `m.texto` (dc L.5094).
 */
final readonly class MovimentacaoParaPrevisao
{
    public function __construct(
        public \DateTimeImmutable $data,
        public string $tipo,
        public string $texto,
    ) {
    }

    /**
     * Do teor em HTML que o DJEN entrega: tags fora, entidades decodificadas, espaços colapsados
     * (o mesmo tratamento do título do cartão, sem corte).
     */
    public static function doTeorHtml(\DateTimeImmutable $data, ?string $tipo, ?string $teorHtml): self
    {
        return new self(
            $data->setTime(0, 0),
            trim((string) $tipo),
            (string) PublicacaoDjenListaItem::resumirTexto($teorHtml, PHP_INT_MAX),
        );
    }
}
