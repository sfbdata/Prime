<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use App\Conector\Service\RequisicaoDoConector;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Os limites de chamadas do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 8), depois do {@see PortaoNoMcpMiddleware}: a
 * pessoa e o escritório vêm da requisição aberta pelo portão, nunca do pedido.
 *
 * - `conector_chamada`: chave pessoa e escritório do token;
 * - `conector_chamada_escritorio`: chave escritório (a soma das pessoas e dos apps dele).
 *
 * Passou de um deles: 429 com `Retry-After` (segundos) e um erro JSON-RPC de mensagem fixa; nada é despachado. Sem
 * requisição aberta (o portão não rodou): 503, falha fechada.
 */
final class LimiteDeChamadasMiddleware implements MiddlewareInterface
{
    public const MENSAGEM = 'Muitas chamadas ao BlueJus em pouco tempo. Aguarde alguns segundos e tente de novo.';

    /** Valor da anotação {@see PortaoNoMcpMiddleware::ANOTACAO_RECUSA} quando o limite barra a chamada. */
    public const MOTIVO_LIMITE = 'limite';

    public function __construct(
        private readonly RequisicaoDoConector $requisicao,
        private readonly RateLimiterFactoryInterface $conectorChamadaLimiter,
        private readonly RateLimiterFactoryInterface $conectorChamadaEscritorioLimiter,
        private readonly ResponseFactoryInterface $respostas,
        private readonly StreamFactoryInterface $corpos,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->requisicao->estaAberta()) {
            return $this->respostas->createResponse(503);
        }

        $contexto = $this->requisicao->atual()->contexto;
        $pessoaId = (int) $contexto->pessoa->getId();
        $escritorioId = (int) $contexto->escritorio->getId();

        $limites = [
            [$this->conectorChamadaLimiter, $pessoaId . ':' . $escritorioId],
            [$this->conectorChamadaEscritorioLimiter, (string) $escritorioId],
        ];
        foreach ($limites as [$fabrica, $chave]) {
            $limite = $fabrica->create($chave)->consume(1);
            if (!$limite->isAccepted()) {
                $this->requisicao->anotar(PortaoNoMcpMiddleware::ANOTACAO_RECUSA, self::MOTIVO_LIMITE);

                return $this->muitasChamadas($limite->getRetryAfter());
            }
        }

        return $handler->handle($request);
    }

    private function muitasChamadas(\DateTimeImmutable $tenteDeNovoEm): ResponseInterface
    {
        $segundos = max(1, (int) ceil((float) $tenteDeNovoEm->format('U.u') - microtime(true)));
        $corpo = json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32000, 'message' => self::MENSAGEM],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        return $this->respostas->createResponse(429)
            ->withHeader('Retry-After', (string) $segundos)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->corpos->createStream($corpo));
    }
}
