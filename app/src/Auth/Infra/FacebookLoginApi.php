<?php

declare(strict_types=1);

namespace App\Auth\Infra;

use App\Auth\DTO\IdentidadeDoFacebook;
use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Login do Facebook (spec login-cupom-e-facebook, PR B), à mão: só identidade (`public_profile,email`), sem guardar
 * token. O Facebook não prova e-mail: o que sai daqui é o `id` (prova) e o e-mail como mera sugestão. Sem o app id e
 * o secret, `ligado()` é falso, o botão some e as rotas respondem 404.
 */
final class FacebookLoginApi
{
    public const ESCOPOS = 'public_profile,email';
    public const VERSAO_PADRAO = 'v21.0';
    private const AUTORIZAR = 'https://www.facebook.com/%s/dialog/oauth';
    private const GRAPH = 'https://graph.facebook.com/%s';
    private const TEMPO_SEGUNDOS = 15;

    private readonly string $versao;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::FACEBOOK_APP_ID)%')]
        private readonly string $appId = '',
        #[Autowire('%env(string:default::FACEBOOK_APP_SECRET)%')]
        private readonly string $segredo = '',
        #[Autowire('%env(string:default::FACEBOOK_GRAPH_VERSION)%')]
        string $versao = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->versao = 1 === preg_match('/^v\d+\.\d+$/', trim($versao)) ? trim($versao) : self::VERSAO_PADRAO;
    }

    public function ligado(): bool
    {
        return '' !== trim($this->appId) && '' !== trim($this->segredo);
    }

    public function urlDeAutorizacao(string $state, string $retorno): string
    {
        return sprintf(self::AUTORIZAR, $this->versao) . '?' . http_build_query([
            'client_id'     => $this->appId,
            'redirect_uri'  => $retorno,
            'state'         => $state,
            'scope'         => self::ESCOPOS,
            'response_type' => 'code',
        ]);
    }

    /** HMAC-SHA256 do token com o secret do app, exigido pelo Graph quando o app pede prova de segredo. */
    public static function appsecretProof(string $token, string $segredo): string
    {
        return hash_hmac('sha256', $token, $segredo);
    }

    /** Troca o código pela identidade. Qualquer falha vira a mensagem neutra da tela. */
    public function identidade(string $codigo, string $retorno): IdentidadeDoFacebook
    {
        $graph = sprintf(self::GRAPH, $this->versao);
        $token = $this->pedir($graph . '/oauth/access_token', [
            'client_id' => $this->appId, 'client_secret' => $this->segredo, 'redirect_uri' => $retorno, 'code' => $codigo,
        ]);
        $acesso = $token['access_token'] ?? null;
        if (!is_string($acesso) || '' === $acesso) {
            $this->logger->warning('Facebook: a troca do código não devolveu token');
            throw new EntradaPeloFacebookRecusadaException(EntradaPeloFacebookRecusadaException::FALHOU);
        }
        $eu = $this->pedir($graph . '/me', [
            'fields' => 'id,name,email', 'access_token' => $acesso, 'appsecret_proof' => self::appsecretProof($acesso, $this->segredo),
        ]);
        $id = $eu['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            $this->logger->warning('Facebook: o /me não devolveu id');
            throw new EntradaPeloFacebookRecusadaException(EntradaPeloFacebookRecusadaException::FALHOU);
        }
        $email = is_string($eu['email'] ?? null) ? trim($eu['email']) : '';

        return new IdentidadeDoFacebook($id, is_string($eu['name'] ?? null) ? trim($eu['name']) : '', '' !== $email ? $email : null);
    }

    /**
     * @param array<string, string> $consulta
     *
     * @return array<string, mixed>
     */
    private function pedir(string $url, array $consulta): array
    {
        try {
            $r = $this->http->request('GET', $url, ['query' => $consulta, 'timeout' => self::TEMPO_SEGUNDOS]);
            $status = $r->getStatusCode();
            $j = $r->toArray(false);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('Facebook: sem resposta do Graph', ['motivo' => $e->getMessage()]);
            throw new EntradaPeloFacebookRecusadaException(EntradaPeloFacebookRecusadaException::FALHOU, 0, $e);
        }
        if ($status >= 400) {
            $this->logger->warning('Facebook: o Graph recusou o pedido', ['status' => $status]);
            throw new EntradaPeloFacebookRecusadaException(EntradaPeloFacebookRecusadaException::FALHOU);
        }

        return $j;
    }
}
