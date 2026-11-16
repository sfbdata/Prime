<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Service\PortaoDoConector;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\ValidadorDoTokenDoConector;
use Mcp\Server\Authorization\AccessToken;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * O portão do conector na cadeia PSR-15 do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 4.1; decisões 1 e 4 do PR 5),
 * logo depois do `AuthorizationMiddleware` do SDK: o token já foi aceito pelo {@see ValidadorDoTokenDoConector} e o
 * escopo, conferido pela `ScopePolicy`. Aqui a requisição é trancada no escritório do token:
 *
 * 1. {@see PortaoDoConector::abrir()} com a pessoa e o escritório da concessão (liga e confere o filtro, fixa o
 *    escritório no TenantContext, liga o modo do conector);
 * 2. {@see RequisicaoDoConector::abrir()} com a {@see ChamadaDoConector}, que o despacho das ferramentas lê;
 * 3. o último uso da conexão ({@see ConsentimentoDoConectorRepository::registrarUso()}).
 *
 * Falha fechada (decisão 4 do PR 1): QUALQUER exceção nesses passos, inclusive `LogicException` de uma abertura que
 * falhou no meio, encerra a requisição com 503, sem desafio e sem tentar de novo; nada é despachado. É o ÚNICO lugar
 * que chama {@see PortaoDoConector::abrir()} (`PortaoNoMcpArquiteturaTest`).
 */
final class PortaoNoMcpMiddleware implements MiddlewareInterface
{
    /** A anotação da chamada com o motivo da recusa do portão, para a linha do log `mcp`. */
    public const ANOTACAO_RECUSA = 'recusa';

    /** Motivo de lista fechada quando a falha não é uma recusa do portão. */
    public const MOTIVO_ERRO = 'erro';

    public function __construct(
        private readonly ValidadorDoTokenDoConector $validador,
        private readonly PortaoDoConector $portao,
        private readonly RequisicaoDoConector $requisicao,
        private readonly ConsentimentoDoConectorRepository $consentimentos,
        private readonly ClockInterface $relogio,
        private readonly ResponseFactoryInterface $respostas,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $this->abrir($request);
        } catch (\Throwable $erro) {
            $this->requisicao->anotar(self::ANOTACAO_RECUSA, $erro instanceof AcessoDoConectorRecusado ? $erro->motivo : self::MOTIVO_ERRO);

            return $this->respostas->createResponse(503);
        }

        return $handler->handle($request);
    }

    private function abrir(ServerRequestInterface $request): void
    {
        // Só depois do AuthorizationMiddleware: sem o token aceito na requisição, nada abre.
        $token = $this->validador->tokenValidado();
        if ($token === null || !$request->getAttribute(AccessToken::class) instanceof AccessToken) {
            throw new \LogicException('O portão do /mcp roda só depois de o token ser aceito.');
        }

        $concessao = $token->getConcessao();
        $escritorio = $token->getTenant() ?? throw new \LogicException('Token sem escritório.');
        $contexto = $this->portao->abrir((int) $concessao->getUser()->getId(), (int) $escritorio->getId());

        $host = parse_url($concessao->getCliente()->getIdentificador(), \PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new \LogicException('App de I.A. sem host no client_id.');
        }
        $consentimentoId = (int) $concessao->getConsentimento()->getId();

        $this->requisicao->abrir(new ChamadaDoConector(
            $contexto,
            (int) $concessao->getId(),
            $consentimentoId,
            $host,
            $token->getEscopos(),
            $concessao->getExpiraEm(),
        ));

        $this->consentimentos->registrarUso($consentimentoId, $contexto->escritorio, $this->relogio->now());
    }
}
