<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/**
 * O que a seção "Integração bancária" mostra ao Master. Nunca o certificado nem os segredos (Client Secret,
 * app key): só os nomes dos arquivos e se cada segredo está definido. Chave PIX e URLs não são segredo.
 */
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
        public bool $clientSecretDefinido = false,
        public string $chavePix = '',
        public bool $appKeyDefinida = false,
        public string $urlBase = '',
        public string $urlToken = '',
    ) {
    }
}
