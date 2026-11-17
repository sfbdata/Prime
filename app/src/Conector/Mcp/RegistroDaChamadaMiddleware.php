<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use App\Conector\Service\RequisicaoDoConector;
use App\Conector\Service\ValidadorDoTokenDoConector;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A linha do log `mcp` de cada requisição do `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 6.2, regra 9): o primeiro
 * middleware da cadeia, então escreve uma linha só, qualquer que seja a resposta (401, 403, 429, 503 ou a da
 * ferramenta).
 *
 * Vai ao log: o status HTTP, a duração, os ids da chamada aberta pelo portão (pessoa, escritório, concessão,
 * consentimento) e o host do app de I.A., o motivo da recusa do token (lista fechada do validador) e as anotações da
 * {@see RequisicaoDoConector} (ferramenta, resultado, contagens, ids). Nunca o corpo, os argumentos, o resultado da
 * ferramenta nem cabeçalho: nada de texto de busca, nome, valor de campo ou CPF (12.15).
 *
 * Requisição aberta não quer dizer sucesso: o portão abre a requisição antes de gravar o último uso e, se essa
 * gravação falha, responde 503 com a requisição aberta e a anotação `recusa`. Quem diz que a chamada deu certo é o
 * status e a anotação `resultado` do despacho; a linha leva os três e nunca deduz nada da abertura.
 *
 * Escrever o log nunca muda a resposta: uma falha ao ler os ids ou o motivo vira o campo `falha_no_registro`, e o
 * resto da linha (status, duração e anotações, inclusive `recusa`) continua.
 */
final class RegistroDaChamadaMiddleware implements MiddlewareInterface
{
    public const MENSAGEM = 'conector: chamada ao /mcp';

    public function __construct(
        private readonly LoggerInterface $mcpLogger,
        private readonly RequisicaoDoConector $requisicao,
        #[Autowire(service: ValidadorDoTokenDoConector::class)]
        private readonly AuthorizationTokenValidatorInterface $validador,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inicio = hrtime(true);
        $status = 500;
        try {
            $resposta = $handler->handle($request);
            $status = $resposta->getStatusCode();

            return $resposta;
        } finally {
            $this->registrar($status, intdiv(hrtime(true) - $inicio, 1_000_000));
        }
    }

    private function registrar(int $status, int $duracaoMs): void
    {
        $linha = ['status' => $status, 'duracao_ms' => $duracaoMs];
        try {
            $linha += $this->idsDaChamada();
        } catch (\Throwable $falha) {
            $linha['falha_no_registro'] = $falha::class;
        }
        try {
            $linha['motivo'] = $this->motivoDaRecusa();
        } catch (\Throwable $falha) {
            $linha['falha_no_registro'] ??= $falha::class;
        }
        $linha += $this->requisicao->anotacoes();

        $this->mcpLogger->info(self::MENSAGEM, $linha);
    }

    /** @return array<string, int|string|null> */
    private function idsDaChamada(): array
    {
        if (!$this->requisicao->estaAberta()) {
            return [];
        }

        $chamada = $this->requisicao->atual();

        return [
            'pessoa' => $chamada->contexto->pessoa->getId(),
            'escritorio' => $chamada->contexto->escritorio->getId(),
            'concessao' => $chamada->concessaoId,
            'consentimento' => $chamada->consentimentoId,
            'cliente' => $chamada->hostDoCliente,
        ];
    }

    private function motivoDaRecusa(): ?string
    {
        return $this->validador instanceof ValidadorDoTokenDoConector ? $this->validador->motivoDaRecusa() : null;
    }
}
