<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\Enum\FormaTotalizador;

/**
 * Uma linha do rodapé somado da planilha, já normalizada (SPEC espelho §4.1, INV-T2).
 *
 * O bloco tem duas formas de layout e o leitor normaliza as duas para os mesmos campos, mas `$forma`
 * registra de qual veio — sem isso não há como provar que a normalização acertou:
 *
 *  - `Larga`    → rótulo em A, valores em H..M (é a linha "Total inadimplência das unidades")
 *  - `Estreita` → rótulo em A, valores em B..G (as linhas por classe e o "Total de inadimplência")
 */
final readonly class TotalizadorEspelhado
{
    public function __construct(
        public int $numeroLinha,
        public FormaTotalizador $forma,
        public string $rotulo,
        public ?int $valor,
        public ?int $juros,
        public ?int $multa,
        public ?int $correcao,
        public ?int $honorarios,
        public ?int $total,
    ) {
    }
}
