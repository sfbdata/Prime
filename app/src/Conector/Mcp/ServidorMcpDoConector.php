<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\Service\ValidadorDoTokenDoConector;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O servidor MCP do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 3.1, 3.5, 5.1 e 8), montado a cada requisição com o
 * `StreamableHttpTransport` do SDK: as três versões do protocolo no mesmo endpoint, sem sessão MCP (nenhum
 * `setSession`), sem assinatura de eventos (nenhum barramento de notificações; a assinatura vale no máximo
 * {@see self::VALIDADE_DA_ASSINATURA} segundo, porque no SDK 0.10 o valor 0 é assinatura sem fim) e com o
 * `NullLogger` no construtor, no transporte e nos middlewares, porque o SDK escreveria no log a mensagem inteira, os
 * argumentos e o resultado da ferramenta (6.2, regra 9). O log `mcp` do conector é só o do
 * {@see RegistroDaChamadaMiddleware}.
 *
 * A cadeia, de fora para dentro:
 *  1. {@see RegistroDaChamadaMiddleware}: uma linha no log `mcp` por requisição, qualquer que seja a resposta;
 *  2. CORS fechado (nenhuma origem liberada: os apps de I.A. chamam de servidor a servidor);
 *  3. proteção de rebinding com os hosts de `conector.mcp_hosts`;
 *  4. {@see EscopoQueFaltaMiddleware}: o 403 do escopo que falta ganha o corpo JSON-RPC do ChatGPT (step-up, 5.1);
 *  5. {@see ConectorDesligadoMiddleware}: o 403 do conector suspenso (liberação desligada) sai sem `WWW-Authenticate`,
 *     com um erro JSON-RPC fixo, para o app não renovar nem pedir reconexão;
 *  6. `AuthorizationMiddleware` com o validador do token e a política de escopo do catálogo: 401 e 403 com
 *     `WWW-Authenticate`, um juiz só;
 *  7. {@see PreflightSemPortaoMiddleware}: o `OPTIONS` recebe o 204 aqui, sem passar pelo portão (que daria 503);
 *  8. o portão (abre a requisição com a pessoa e o escritório do token) e
 *  9. o limite de chamadas.
 * Nunca o `ProtocolVersionMiddleware`: o transporte já o aplica só à era do `initialize`, e na lista ele recusaria a
 * 2026-07-28.
 *
 * O portão e o limite são obrigatórios: o container injeta o {@see PortaoNoMcpMiddleware} e o
 * {@see LimiteDeChamadasMiddleware} pelo id do serviço (`#[Autowire(service: ...)]`). Nunca `#[Autowire('@?...')]`: o
 * atributo não entende o `?` do YAML, procura o id `?App\...`, que não existe, e o parâmetro anulável virava null.
 */
final class ServidorMcpDoConector
{
    public const NOME = 'BlueJus';
    public const VERSAO = '1.0.0';

    /**
     * Segundos que um `subscriptions/listen` fica aberto. Nunca 0: no SDK 0.10 o 0 é sem fim
     * (`StatelessProtocol::listen`). Hoje, sem barramento, o fluxo já encerra no reconhecimento; o teto de 1 s é para
     * nunca segurar um processo do PHP-FPM se um dia houver notificações.
     */
    public const VALIDADE_DA_ASSINATURA = 1.0;

    /** @param list<string> $hostsPermitidos */
    public function __construct(
        private readonly RegistroDasFerramentas $ferramentas,
        #[Autowire(service: ValidadorDoTokenDoConector::class)]
        private readonly AuthorizationTokenValidatorInterface $validador,
        private readonly RegistroDaChamadaMiddleware $registro,
        private readonly EscopoQueFaltaMiddleware $escopoQueFalta,
        #[Autowire(service: PortaoNoMcpMiddleware::class)]
        private readonly MiddlewareInterface $portao,
        #[Autowire(service: LimiteDeChamadasMiddleware::class)]
        private readonly MiddlewareInterface $limite,
        #[Autowire(param: 'conector.mcp_hosts')]
        private readonly array $hostsPermitidos,
        private readonly ResponseFactoryInterface $respostas,
        private readonly StreamFactoryInterface $fluxos,
    ) {
    }

    /** O metadado do recurso (RFC 9728): o mesmo no `WWW-Authenticate` e nas duas URLs do controller (3.3). */
    public static function metadadoDoRecurso(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata(
            ServidorDeAutorizacao::RESOURCE,
            [ServidorDeAutorizacao::ISSUER],
            ServidorDeAutorizacao::ESCOPOS_DO_RECURSO,
        );
    }

    public function responder(ServerRequestInterface $pedido): ResponseInterface
    {
        $transporte = new StreamableHttpTransport(
            $pedido,
            $this->respostas,
            $this->fluxos,
            new NullLogger(),
            $this->middlewares(),
        );

        return $this->servidor()->run($transporte);
    }

    /** @return list<MiddlewareInterface> */
    public function middlewares(): array
    {
        return [
            $this->registro,
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware($this->hostsPermitidos, [], $this->respostas, $this->fluxos),
            $this->escopoQueFalta,
            new ConectorDesligadoMiddleware($this->respostas, $this->fluxos),
            new AuthorizationMiddleware(
                $this->validador,
                self::metadadoDoRecurso(),
                CatalogoFechadoDoConector::politicaDeEscopo(),
                $this->respostas,
                $this->fluxos,
                logger: new NullLogger(),
            ),
            new PreflightSemPortaoMiddleware($this->respostas),
            $this->portao,
            $this->limite,
        ];
    }

    private function servidor(): Server
    {
        $construtor = Server::builder()
            ->setServerInfo(self::NOME, self::VERSAO)
            ->setLogger(new NullLogger())
            ->setSubscriptionLifetime(self::VALIDADE_DA_ASSINATURA);
        $this->ferramentas->registrarNo($construtor);

        return $construtor->build();
    }
}
