<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

/**
 * Credenciais de um banco da API Pix do BACEN, já decifradas (vivem só na memória da chamada). As
 * diferenças entre os bancos ficam aqui: URLs, como o token é pedido (Basic ou no corpo) e os parâmetros
 * de consulta e os cabeçalhos que toda chamada à API leva (a app key do Banco do Brasil, a conta corrente
 * do Inter).
 */
final readonly class CredenciaisBacen
{
    /**
     * @param array<string, string> $parametrosDaApi
     * @param array<string, string> $cabecalhosDaApi
     */
    public function __construct(
        public string $banco,
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        #[\SensitiveParameter]
        public string $certificadoPem,
        #[\SensitiveParameter]
        public string $chavePem,
        /** A chave PIX da conta que recebe (o `chave` da cobrança). */
        public string $chavePix,
        public string $urlToken,
        public string $urlBase,
        /** `Authorization: Basic client_id:client_secret` (RFC 6749 §2.3.1); senão os dois vão no corpo. */
        public bool $tokenPorBasic,
        #[\SensitiveParameter]
        public array $parametrosDaApi = [],
        /** Cabeçalhos de toda chamada à API, não do token (o `x-conta-corrente` do Inter). */
        public array $cabecalhosDaApi = [],
    ) {
    }

    /** Chave do token guardado: muda com o banco, a URL do token, o Client ID, o segredo e o certificado. */
    public function chaveDoToken(): string
    {
        return 'contribua_bacen_token_' . hash('sha256', implode('|', [$this->banco, $this->urlToken, $this->clientId, $this->clientSecret, $this->certificadoPem]));
    }

    /**
     * O que nunca pode aparecer numa mensagem (o segredo e a app key).
     *
     * @return list<string>
     */
    public function segredos(): array
    {
        return array_values(array_filter([$this->clientSecret, ...array_values($this->parametrosDaApi)], static fn (string $s): bool => '' !== $s));
    }
}
