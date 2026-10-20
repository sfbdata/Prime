<?php

declare(strict_types=1);

namespace App\Contribua\Banco\Bacen;

use App\Contribua\Banco\ClienteHttpDosBancos;
use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\FalhaNoBanco;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Chamadas à API Pix do BACEN (manual do Pix, "API Pix" v2: bacen.github.io/pix-api), a base comum do Inter,
 * do Banco do Brasil e do "Outro". Regras do padrão:
 * - token OAuth2 `client_credentials` com Client ID e Client Secret e os escopos da cobrança imediata;
 * - mTLS em TODA chamada (certificado e chave numa área temporária privada, apagada no fim);
 * - token guardado cifrado pelo tempo que o banco der (menos uma folga); um 401 descarta e tenta uma vez;
 * - falha passageira (rede, 5xx, 401, 403, 429) põe a página no PIX estático por uns minutos; o resto é
 *   recusa do pedido.
 * O código HTTP vai no código da FalhaNoBanco, para o adaptador saber se a cobrança já existia.
 * Nenhuma mensagem leva o segredo, a app key ou a query string da URL.
 */
class ClientePixBacen
{
    public const ESCOPOS = 'cob.write cob.read pix.read webhook.read webhook.write';
    private const TEMPO_SEGUNDOS = 15;
    /** Validade quando o banco não diz (`expires_in`); o BB dá 600 s e o Inter 3600 s. */
    private const VALIDADE_PADRAO_SEGUNDOS = 600;
    private const FOLGA_SEGUNDOS = 60;

    public function __construct(
        /** Sem logger e sem redirecionamento (ClienteHttpDosBancos): a URL e o segredo não vão para o log. */
        #[Autowire(service: ClienteHttpDosBancos::class)]
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        private readonly CofreDeCertificado $cofre,
    ) {
    }

    /** Pede um token novo (Testar conexão) e o guarda no lugar do anterior. */
    public function token(CredenciaisBacen $c): string
    {
        $this->cache->deleteItem($c->chaveDoToken());

        return $this->comArquivos($c, fn (array $mtls): string => $this->tokenGuardado($c, $mtls));
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, mixed>
     */
    public function pedir(CredenciaisBacen $c, string $metodo, string $caminho, array $opcoes = []): array
    {
        return $this->comArquivos($c, function (array $mtls) use ($c, $metodo, $caminho, $opcoes): array {
            [$status, $corpo] = $this->chamar($c, $mtls, $metodo, $caminho, $opcoes, $this->tokenGuardado($c, $mtls));
            if (401 === $status) {
                $this->cache->deleteItem($c->chaveDoToken());
                [$status, $corpo] = $this->chamar($c, $mtls, $metodo, $caminho, $opcoes, $this->tokenGuardado($c, $mtls));
            }
            if ($status >= 400) {
                throw new FalhaNoBanco($this->motivo($c, $status, $corpo), $status, null, self::passageira($status));
            }

            return $corpo;
        });
    }

    public static function passageira(int $status): bool
    {
        return $status >= 500 || 401 === $status || 403 === $status || 429 === $status;
    }

    /**
     * @param array{local_cert: string, local_pk: string} $mtls
     * @param array<string, mixed>                        $opcoes
     *
     * @return array{int, array<string, mixed>}
     */
    private function chamar(CredenciaisBacen $c, array $mtls, string $metodo, string $caminho, array $opcoes, string $token): array
    {
        $opcoes['query'] = (array) ($opcoes['query'] ?? []) + $c->parametrosDaApi;
        try {
            $r = $this->http->request($metodo, rtrim($c->urlBase, '/') . $caminho, $mtls + $opcoes + ['auth_bearer' => $token, 'timeout' => self::TEMPO_SEGUNDOS, 'max_redirects' => 0]);

            return [$r->getStatusCode(), $this->corpo($r->getContent(false))];
        } catch (ExceptionInterface $e) {
            throw new FalhaNoBanco('Sem resposta do banco: ' . $this->limpar($c, $e->getMessage()), 0, $e);
        }
    }

    /** @param array{local_cert: string, local_pk: string} $mtls */
    private function tokenGuardado(CredenciaisBacen $c, array $mtls): string
    {
        $item = $this->cache->getItem($c->chaveDoToken());
        if ($item->isHit() && is_string($item->get())) {
            return $this->cofre->decifrar($item->get());
        }
        [$token, $validade] = $this->pedirToken($c, $mtls);
        $this->cache->save($item->set($this->cofre->cifrar($token))->expiresAfter(max(self::FOLGA_SEGUNDOS, $validade - self::FOLGA_SEGUNDOS)));

        return $token;
    }

    /**
     * @param array{local_cert: string, local_pk: string} $mtls
     *
     * @return array{string, int} o token e quantos segundos ele vale
     */
    private function pedirToken(CredenciaisBacen $c, array $mtls): array
    {
        $corpo = ['grant_type' => 'client_credentials', 'scope' => self::ESCOPOS];
        $opcoes = $mtls + ['timeout' => self::TEMPO_SEGUNDOS, 'max_redirects' => 0];
        if ($c->tokenPorBasic) {
            $opcoes['auth_basic'] = [$c->clientId, $c->clientSecret];
        } else {
            $corpo += ['client_id' => $c->clientId, 'client_secret' => $c->clientSecret];
        }
        try {
            $r = $this->http->request('POST', $c->urlToken, $opcoes + ['body' => $corpo]);
            $status = $r->getStatusCode();
            $resposta = $this->corpo($r->getContent(false));
        } catch (ExceptionInterface $e) {
            throw new FalhaNoBanco('Sem resposta do banco: ' . $this->limpar($c, $e->getMessage()), 0, $e);
        }
        if (200 !== $status || !is_string($resposta['access_token'] ?? null) || '' === $resposta['access_token']) {
            throw new FalhaNoBanco($this->motivo($c, $status, $resposta), $status);
        }

        return [$resposta['access_token'], (int) ($resposta['expires_in'] ?? self::VALIDADE_PADRAO_SEGUNDOS)];
    }

    /** @return array<string, mixed> */
    private function corpo(string $conteudo): array
    {
        if ('' === trim($conteudo)) {
            return [];
        }
        $dados = json_decode($conteudo, true);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Certificado e chave em arquivos só durante a chamada (o HttpClient pede caminhos), numa área temporária
     * privada da operação (diretório 0700 de nome aleatório), apagada no fim.
     *
     * @template T
     *
     * @param callable(array{local_cert: string, local_pk: string}): T $acao
     *
     * @return T
     */
    private function comArquivos(CredenciaisBacen $c, callable $acao): mixed
    {
        try {
            $area = AreaTemporariaPrivada::criar('pix');
        } catch (FalhaNoTemporario $e) {
            throw new FalhaNoBanco('Não foi possível preparar o certificado.', 0, $e);
        }
        try {
            $mtls = ['local_cert' => $area->gravar($c->certificadoPem, 'pem'), 'local_pk' => $area->gravar($c->chavePem, 'key')];

            return $acao($mtls);
        } catch (FalhaNoTemporario $e) {
            throw new FalhaNoBanco('Não foi possível preparar o certificado.', 0, $e);
        } finally {
            $area->liberar();
        }
    }

    /**
     * Erro no formato do padrão (RFC 7807: `title`, `detail`, `violacoes`), do OAuth (`error_description`)
     * ou do BB (`erros[0].mensagem`).
     *
     * @param array<string, mixed> $corpo
     */
    private function motivo(CredenciaisBacen $c, int $status, array $corpo): string
    {
        $erro = $corpo['violacoes'][0]['razao'] ?? $corpo['detail'] ?? $corpo['title'] ?? $corpo['erros'][0]['mensagem']
            ?? $corpo['error_description'] ?? $corpo['message'] ?? $corpo['error'] ?? null;

        return 'O banco respondeu ' . $status . (is_string($erro) ? ': ' . mb_substr($this->limpar($c, $erro), 0, 150) : '') . '.';
    }

    /** Tira da mensagem a query string das URLs (a app key do BB vai nela) e qualquer segredo. */
    private function limpar(CredenciaisBacen $c, string $mensagem): string
    {
        $semQuery = (string) preg_replace('/\?[^\s"\']*/', '', $mensagem);

        return str_replace($c->segredos(), '***', $semQuery);
    }
}
