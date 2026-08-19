<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * A lista de obrigações que o dono aprovou não casa com o que a varredura encontrou agora — spec
 * `cobranca-honorario-no-total.md` §10.5.2.
 *
 * 🔑 **Existe porque a alternativa era adivinhar.** Três revisões desta fatia derrubaram três réguas
 * automáticas diferentes para decidir QUAIS parcelas corrigir (procedência, papel, marca na
 * descrição): todas erravam em algum recorte, porque o dado que decide — o Valor acordado que a
 * contabilidade declara para aquela parcela — não está no banco no momento da correção. Em vez de um
 * quarto proxy, a escrita passa a exigir a lista explícita que o humano aprovou olhando a simulação.
 *
 * É lançada DENTRO da transação, então nada é gravado.
 */
final class AprovacaoNaoConfereException extends \RuntimeException
{
    /** @param list<int> $idsQueSumiram */
    public function __construct(public readonly array $idsQueSumiram)
    {
        parent::__construct(sprintf(
            'A lista aprovada não confere: %d obrigação(ões) que você aprovou não estão mais entre as '
            . 'candidatas (%s). Nada foi gravado. Rode a simulação de novo e reaprove.',
            count($idsQueSumiram),
            implode(', ', array_slice($idsQueSumiram, 0, 20)) . (count($idsQueSumiram) > 20 ? ', …' : ''),
        ));
    }
}
