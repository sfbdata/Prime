<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/**
 * O documento pronto para o PDF "Baixar para imprimir" (função provisória): o texto inteiro, sem HTML do usuário (cada
 * `html` já saiu escapado, com `<b>` só nos trechos {b}...{/b}) e um espaço de assinatura à mão para cada signatário.
 */
final readonly class FolhaParaImprimir
{
    /**
     * @param list<array{tipo: string, html: string, nome: string, papel: string, pagina: int}> $itens      na ordem do documento
     * @param list<array{nome: string, papel: string}>                                         $sobraram   signatários sem lugar de assinatura no texto
     * @param bool                                                                              $semNinguem nenhum signatário nem lugar de assinatura: sai uma linha em branco
     */
    public function __construct(
        public string $nome,
        public string $lateral, // já escapado, como os `html` dos itens
        public array $itens,
        public array $sobraram,
        public bool $semNinguem,
    ) {
    }
}
