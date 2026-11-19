<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O saldo de I.A. gratuita de UMA pessoa (spec `ia-liberada-por-indicacao.md`, seções 4 e 12): o que ela já gastou, o
 * que tem direito e a leitura em "peças" para a barra de uso. `seAplica` falso: a pessoa só está em escritório com
 * plano pago, e a barra some. `unidade` é a da medida em uso ('tokens' ou 'pecas'): `tokensUsados` e `tokensDireito`
 * estão nessa unidade.
 */
final readonly class SaldoDeIaOutput
{
    public function __construct(
        public bool $seAplica,
        public int $tokensUsados,
        public int $tokensDireito,
        public float $pecasUsadas,
        public int $pecasDireito,
        public int $percentual,
        public bool $esgotado,
        public bool $perto,
        public int $colegasCadastrados,
        public string $unidade = 'tokens',
    ) {
    }
}
