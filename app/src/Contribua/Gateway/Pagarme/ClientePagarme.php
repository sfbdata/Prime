<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Pagarme;

use App\Contribua\Gateway\FalhaNoGateway;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * O HTTP da API v5 do Pagar.me (https://docs.pagar.me/reference/introdução-1): JSON nos dois sentidos e
 * Basic com a chave secreta como usuário e senha vazia (https://docs.pagar.me/reference/autenticação-2). O
 * mesmo endereço serve teste e produção: o tipo da chave (`sk_test_` ou `sk_`) decide. Toda falha (rede, JSON
 * inválido, HTTP 4xx/5xx) vira `FalhaNoGateway`, com o status HTTP no código da exceção (0 sem resposta).
 */
final class ClientePagarme
{
    private const ENDERECO = 'https://api.pagar.me/core/v5';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(string:default::CONTRIBUA_PAGARME_SECRET_KEY)%')]
        private readonly string $chaveSecreta,
    ) {
    }

    /**
     * @param array<string, mixed> $corpo
     *
     * @return array<string, mixed>
     */
    public function enviar(string $metodo, string $caminho, array $corpo): array
    {
        return $this->pedir($metodo, $caminho, ['json' => $corpo]);
    }

    /**
     * @param array<string, mixed> $consulta
     *
     * @return array<string, mixed>
     */
    public function ler(string $caminho, array $consulta = []): array
    {
        return $this->pedir('GET', $caminho, ['query' => $consulta]);
    }

    /**
     * @param array<string, mixed> $opcoes
     *
     * @return array<string, mixed>
     */
    private function pedir(string $metodo, string $caminho, array $opcoes): array
    {
        if ($this->chaveSecreta === '') {
            throw new FalhaNoGateway('CONTRIBUA_PAGARME_SECRET_KEY não configurada.');
        }
        $opcoes['auth_basic'] = [$this->chaveSecreta, ''];

        try {
            $resposta = $this->http->request($metodo, self::ENDERECO . $caminho, $opcoes);
            $status = $resposta->getStatusCode();
            $dados = $resposta->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new FalhaNoGateway(sprintf('Pagar.me sem resposta em %s %s: %s', $metodo, $caminho, $e->getMessage()), 0, $e);
        }

        return $status < 400 ? $dados : throw self::falha($metodo, $caminho, $status, $dados);
    }

    /**
     * Erro do Pagar.me: `{"message": "...", "errors": {"campo": ["..."]}}` (https://docs.pagar.me/reference/erros-1).
     *
     * @param array<string, mixed> $dados
     */
    private static function falha(string $metodo, string $caminho, int $status, array $dados): FalhaNoGateway
    {
        $detalhes = \is_array($dados['errors'] ?? null) ? (string) json_encode($dados['errors'], \JSON_UNESCAPED_UNICODE) : '';

        return new FalhaNoGateway(trim(sprintf(
            'Pagar.me respondeu %d em %s %s: %s %s',
            $status,
            $metodo,
            $caminho,
            (string) ($dados['message'] ?? 'sem mensagem'),
            $detalhes,
        )), $status);
    }
}
