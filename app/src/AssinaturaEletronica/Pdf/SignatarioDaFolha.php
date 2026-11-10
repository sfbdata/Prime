<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Um signatário no lugar de assinatura (bloco `a`): o nome impresso quando o bloco não traz papel (o digitado ao
 * assinar, senão o cadastrado), quando assinou (nulo = pendente, sem rubrica nem "Assinado em") e os bytes da rubrica.
 * A data sai no fuso do próprio objeto.
 */
final readonly class SignatarioDaFolha
{
    public function __construct(
        public string $nome,
        public ?\DateTimeImmutable $assinadoEm = null,
        public ?string $rubrica = null,
    ) {
    }

    public function assinou(): bool
    {
        return $this->assinadoEm !== null;
    }
}
