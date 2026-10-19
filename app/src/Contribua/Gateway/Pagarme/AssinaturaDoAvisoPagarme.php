<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Pagarme;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use App\Contribua\Gateway\FalhaNoGateway;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Confere o cabeçalho `X-Webhook-Asymmetric-Signature` de um webhook do Pagar.me:
 * `alg=RS256; kid=<id>; signature=<base64url>`, assinatura RSA-SHA256 do corpo bruto, conferida com a chave
 * pública de mesmo `kid` publicada em `JWKS`.
 *
 * A docs.pagar.me não documenta a assinatura do webhook: o formato vem do código oficial dos plugins
 * (`pagarme/ecommerce-module-core`, `WebhookValidatorService`, usado pelos módulos de Magento 2 e
 * WooCommerce). As chaves ficam 6 h no cache; se a assinatura não confere com as do cache, busca de novo uma
 * vez (troca de chave no Pagar.me). Sem a lista de chaves não dá para decidir: `FalhaNoGateway`, e o
 * Pagar.me reenvia o aviso.
 */
final class AssinaturaDoAvisoPagarme
{
    public const JWKS = 'https://hubapi.pagar.me/.well-known/jwks.json';
    private const CHAVE_DO_CACHE = 'contribua_pagarme_jwks';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @throws AvisoNaoAutenticado */
    public function exigirAutentico(string $cabecalho, string $corpo): void
    {
        $partes = self::partes($cabecalho);
        $assinatura = self::base64url($partes['signature'] ?? '');
        if (($partes['alg'] ?? null) !== 'RS256' || ($partes['kid'] ?? '') === '' || $assinatura === null || $corpo === '') {
            throw new AvisoNaoAutenticado('Aviso do Pagar.me sem X-Webhook-Asymmetric-Signature válido.');
        }
        if ($this->confere($corpo, $assinatura, $partes['kid'], false) || $this->confere($corpo, $assinatura, $partes['kid'], true)) {
            return;
        }

        throw new AvisoNaoAutenticado('Assinatura do aviso do Pagar.me não confere.');
    }

    private function confere(string $corpo, string $assinatura, string $kid, bool $buscarDeNovo): bool
    {
        if ($buscarDeNovo) {
            $this->cache->delete(self::CHAVE_DO_CACHE);
        }
        $chave = $this->chaves()[$kid] ?? null;

        return $chave !== null && openssl_verify($corpo, $assinatura, $chave, \OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array<string, string> PEM de cada chave RSA de assinatura, por `kid` */
    private function chaves(): array
    {
        return $this->cache->get(self::CHAVE_DO_CACHE, function (ItemInterface $item): array {
            $item->expiresAfter(6 * 3600);
            $chaves = [];
            foreach ($this->buscarJwks() as $jwk) {
                $pem = \is_array($jwk) && ($jwk['kty'] ?? null) === 'RSA' && ($jwk['use'] ?? 'sig') === 'sig' && \is_string($jwk['kid'] ?? null)
                    ? ChavePublicaRsa::pemDoJwk((string) ($jwk['n'] ?? ''), (string) ($jwk['e'] ?? ''))
                    : null;
                if ($pem !== null) {
                    $chaves[$jwk['kid']] = $pem;
                }
            }

            return $chaves;
        });
    }

    /** @return list<mixed> */
    private function buscarJwks(): array
    {
        try {
            $jwks = $this->http->request('GET', self::JWKS)->toArray();
        } catch (ExceptionInterface $e) {
            throw new FalhaNoGateway('Chaves do webhook do Pagar.me indisponíveis: ' . $e->getMessage(), 0, $e);
        }

        return \is_array($jwks['keys'] ?? null) ? array_values($jwks['keys']) : [];
    }

    /** @return array<string, string> */
    private static function partes(string $cabecalho): array
    {
        $partes = [];
        foreach (explode(';', $cabecalho) as $item) {
            [$chave, $valor] = array_pad(explode('=', trim($item), 2), 2, '');
            $partes[strtolower(trim($chave))] = trim($valor);
        }

        return $partes;
    }

    private static function base64url(string $texto): ?string
    {
        return ChavePublicaRsa::base64url($texto);
    }
}
