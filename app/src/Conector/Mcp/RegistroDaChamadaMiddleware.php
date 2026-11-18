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
 * {@see RequisicaoDoConector} (ferramenta, resultado, contagens, ids). Do pedido, só dois nomes do próprio protocolo,
 * conferidos por formato: o método JSON-RPC (`metodo`, ex.: `tools/list`) e a versão do protocolo do cabeçalho
 * `MCP-Protocol-Version` (`versao`). Sem eles, uma chamada que o SDK responde sem chegar à ferramenta (descoberta,
 * lista) não se distingue de outra no log de produção. Nunca o resto do corpo, os argumentos, o resultado da
 * ferramenta nem outro cabeçalho: nada de texto de busca, nome, valor de campo ou CPF (12.15).
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

    /** O método JSON-RPC: letras, `/` e `_`, até 60 caracteres (os nomes do protocolo); o resto não vai ao log. */
    private const FORMATO_DO_METODO = '#^[a-zA-Z][a-zA-Z/_]{0,59}$#';

    /** A versão do protocolo é sempre uma data (`2025-06-18`). */
    private const FORMATO_DA_VERSAO = '/^\d{4}-\d{2}-\d{2}$/';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inicio = hrtime(true);
        $status = 500;
        $doPedido = $this->doPedido($request);
        try {
            $resposta = $handler->handle($request);
            $status = $resposta->getStatusCode();

            return $resposta;
        } finally {
            $this->registrar($status, intdiv(hrtime(true) - $inicio, 1_000_000), $doPedido);
        }
    }

    /**
     * O método JSON-RPC e a versão do protocolo, quando têm o formato esperado. O corpo só é lido se o fluxo volta ao
     * começo depois (o SDK o lê de novo); lote, corpo inválido ou campo fora do formato ficam de fora. Nunca lança.
     *
     * @return array{metodo?: string, versao?: string}
     */
    private function doPedido(ServerRequestInterface $request): array
    {
        $campos = [];
        try {
            $versao = $request->getHeaderLine('MCP-Protocol-Version');
            if (preg_match(self::FORMATO_DA_VERSAO, $versao) === 1) {
                $campos['versao'] = $versao;
            }

            $corpo = $request->getBody();
            if (!$corpo->isSeekable()) {
                return $campos;
            }
            $corpo->rewind();
            $mensagem = json_decode($corpo->getContents(), true);
            $corpo->rewind();
            $metodo = \is_array($mensagem) ? ($mensagem['method'] ?? null) : null;
            if (\is_string($metodo) && preg_match(self::FORMATO_DO_METODO, $metodo) === 1) {
                $campos['metodo'] = $metodo;
            }
        } catch (\Throwable) {
            // Ler o pedido para o log nunca muda a resposta.
        }

        return $campos;
    }

    /** @param array{metodo?: string, versao?: string} $doPedido */
    private function registrar(int $status, int $duracaoMs, array $doPedido): void
    {
        $linha = ['status' => $status, 'duracao_ms' => $duracaoMs] + $doPedido;
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
