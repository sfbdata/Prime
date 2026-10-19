<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Cora;

/** Credenciais da Integração Direta da Cora, já decifradas (vivem só na memória da chamada). */
final readonly class CredenciaisCora
{
    public function __construct(
        public string $ambiente,
        public string $clientId,
        public string $certificadoPem,
        public string $chavePem,
    ) {
    }

    public function base(): string
    {
        return 'producao' === $this->ambiente ? ClienteCora::BASE_PRODUCAO : ClienteCora::BASE_STAGE;
    }

    /** Chave do token guardado: muda com o ambiente, o Client ID e o certificado. */
    public function chaveDoToken(): string
    {
        return 'contribua_cora_token_' . hash('sha256', $this->ambiente . '|' . $this->clientId . '|' . $this->certificadoPem);
    }
}
