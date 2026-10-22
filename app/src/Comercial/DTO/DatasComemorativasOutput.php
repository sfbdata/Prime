<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** O bloco "Datas comemorativas" (desenho 05 VS 21.10.26 L335-339): as 17 linhas e o "N ativas" do subtítulo. */
final class DatasComemorativasOutput
{
    /** @param list<DataComemorativaOutput> $datas */
    public function __construct(
        public readonly array $datas,
        public readonly int $ativas,
    ) {
    }
}
