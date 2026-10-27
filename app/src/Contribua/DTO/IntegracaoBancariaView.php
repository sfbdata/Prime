<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** O que a seção "Integração bancária" mostra ao Master. Nunca o certificado: só os nomes e se está presente. */
final readonly class IntegracaoBancariaView
{
    /**
     * @param list<string>        $arquivos
     * @param array<string, bool> $recursos
     */
    public function __construct(
        public string $modalidade,
        public string $banco,
        public string $ambiente,
        public string $clientId,
        public bool $certificadoPresente,
        public array $arquivos,
        public array $recursos,
        public string $estado,
        public ?string $resultadoDoTeste,
    ) {
    }
}
