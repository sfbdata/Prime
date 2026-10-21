<?php

declare(strict_types=1);

namespace App\Auth\Infra;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Shared\Google\IdTokenDoGoogle;
use App\Shared\Google\IdTokenDoGoogleInvalidoException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenID Connect do Google para o "Entrar com o Google" (spec entrar-com-google): só identidade, com `openid email
 * profile`, sem `offline` (nenhum token é guardado). Cliente OAuth próprio, separado do da Agenda: revogar um não
 * derruba o outro. Sem as duas variáveis, `ligado()` é falso e o botão não aparece.
 */
final class GoogleLoginApi
{
    public const ESCOPOS = 'openid email profile';
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN = 'https://oauth2.googleapis.com/token';
    private const TEMPO_SEGUNDOS = 15;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::GOOGLE_LOGIN_OAUTH_CLIENT_ID)%')]
        private readonly string $clienteId = '',
        #[Autowire('%env(string:default::GOOGLE_LOGIN_OAUTH_CLIENT_SECRET)%')]
        private readonly string $segredo = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function ligado(): bool
    {
        return '' !== trim($this->clienteId) && '' !== trim($this->segredo);
    }

    /** `state` contra CSRF, PKCE S256 (`desafio`) e `nonce` (volta dentro do `id_token`); o seletor de contas sempre. */
    public function urlDeAutorizacao(string $state, string $desafio, string $nonce, string $retorno): string
    {
        return self::AUTORIZAR . '?' . http_build_query([
            'client_id'             => $this->clienteId,
            'redirect_uri'          => $retorno,
            'response_type'         => 'code',
            'scope'                 => self::ESCOPOS,
            'prompt'                => 'select_account',
            'include_granted_scopes' => 'false',
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => $desafio,
            'code_challenge_method' => 'S256',
        ]);
    }

    /** Troca o código pela identidade conferida. Qualquer falha vira a mensagem neutra da tela. */
    public function identidade(string $codigo, string $verificador, string $retorno, string $nonce): IdentidadeDoGoogle
    {
        $token = $this->token(['grant_type' => 'authorization_code', 'code' => $codigo, 'code_verifier' => $verificador, 'redirect_uri' => $retorno]);
        try {
            $carga = (new IdTokenDoGoogle())->conferir(is_string($token['id_token'] ?? null) ? $token['id_token'] : '', $this->clienteId, $nonce);
        } catch (IdTokenDoGoogleInvalidoException $e) {
            $this->logger->warning('Entrar com o Google: identificação recusada', ['motivo' => $e->getMessage()]);
            throw new EntradaPeloGoogleRecusadaException(EntradaPeloGoogleRecusadaException::FALHOU, 0, $e);
        }

        return new IdentidadeDoGoogle((string) $carga['sub'], (string) $carga['email'], is_string($carga['name'] ?? null) ? trim($carga['name']) : '');
    }

    /**
     * @param array<string, string> $campos
     *
     * @return array<string, mixed>
     */
    private function token(array $campos): array
    {
        try {
            $r = $this->http->request('POST', self::TOKEN, ['body' => $campos + ['client_id' => $this->clienteId, 'client_secret' => $this->segredo], 'timeout' => self::TEMPO_SEGUNDOS]);
            $status = $r->getStatusCode();
            $j = $r->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Entrar com o Google: sem resposta do Google', ['motivo' => $e->getMessage()]);
            throw new EntradaPeloGoogleRecusadaException(EntradaPeloGoogleRecusadaException::FALHOU, 0, $e);
        }
        if ($status >= 400) {
            $this->logger->warning('Entrar com o Google: troca do código recusada', ['status' => $status, 'erro' => $j['error'] ?? null]);
            throw new EntradaPeloGoogleRecusadaException(EntradaPeloGoogleRecusadaException::FALHOU);
        }

        return $j;
    }
}
