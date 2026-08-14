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
 *
 * Os outros dois layouts que produzem totalizador reaproveitam a forma `Estreita` e usam só os campos
 * que têm: **acordos** guardam o `Valor final acordado` da aba em `$valor`, com o nome da aba no
 * rótulo; **receitas** guardam `Valor` em `$valor` e `Valor recebido` em `$valorRecebido`.
 */
final readonly class TotalizadorEspelhado
{
    /**
     * @param ?int $valorRecebido a segunda coluna de dinheiro do relatório de RECEITAS
     *
     * 🔑 Campo próprio, e não `$total` "porque cabe". `Valor recebido` não é o total de nada — é
     * quanto entrou de um valor que podia ter entrado inteiro ou em parte. Encaixá-lo num campo com
     * outro significado é o defeito que produz número errado com cara de certo, e este módulo já o
     * levou duas vezes.
     */
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
        public ?int $valorRecebido = null,
    ) {
    }
}
