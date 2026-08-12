<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\BlocoRelatorio;

/**
 * Uma linha do arquivo, já classificada mas ainda NÃO interpretada
 * (SPEC espelho §4.1, INV-L1).
 *
 * Os valores monetários vêm em centavos, e são `null` quando a célula estava vazia — célula vazia e
 * célula com zero são coisas diferentes na planilha, e achatar as duas em `0` já seria interpretar.
 *
 * `$bruto` guarda as células como texto, na ordem do arquivo. É a prova de que a conversão para
 * centavos acertou, para quando alguém duvidar do número.
 */
final readonly class LinhaEspelhada
{
    /**
     * @param list<string> $bruto
     */
    public function __construct(
        public int $numeroLinha,
        public BlocoRelatorio $bloco,
        public ?string $unidade,
        public ?string $sacado,
        public ?string $nn,
        public ?string $classe,
        public ?string $competencia,
        public ?\DateTimeImmutable $vencimento,
        public ?int $atraso,
        public ?int $valor,
        public ?int $juros,
        public ?int $multa,
        public ?int $correcao,
        public ?int $honorarios,
        public ?int $total,
        public ?string $acordoTexto,
        public ?string $recebimento,
        public array $bruto,
    ) {
    }
}
