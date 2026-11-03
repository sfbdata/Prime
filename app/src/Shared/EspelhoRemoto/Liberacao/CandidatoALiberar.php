<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * Um arquivo que passou nos critérios baratos da liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md`
 * §5.2): referenciado no banco, com envio `verificado` como linha mais nova da outbox, sem liberação
 * aberta, no disco com o tamanho da outbox, e frio. O SHA local é conferido depois, só para o lote.
 */
final readonly class CandidatoALiberar
{
    public function __construct(
        public ChaveDeArquivo $chave,
        public string $chaveRemota,
        /** O SHA-256 do envio `verificado`: o que o R2 tem de devolver e o disco tem de ter. */
        public string $sha256,
        public int $tamanhoBytes,
        /** A data que decide a ordem e o frio: a do banco, ou o `mtime` quando o banco não tem. */
        public \DateTimeImmutable $dataDeReferencia,
    ) {
    }
}
