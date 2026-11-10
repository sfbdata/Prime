<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Um signatário no manifesto (dc:418), com os textos já prontos: nome, "papel · CPF · e-mail", "Tipo: lei · método",
 * situação e a nota do IP. `indice` é a posição dele em {@see FolhaParaMontar::$signatarios}: a rubrica sai de
 * {@see RecursosDaFolha::rubrica()} pelo mesmo índice (só de quem assinou).
 */
final readonly class CartaoDoManifesto
{
    public function __construct(
        public int $indice,
        public string $nome,
        public string $dados,
        public string $tipo,
        public SituacaoNoManifesto $situacao,
        public string $textoDaSituacao,
        public string $nota,
    ) {
    }
}
