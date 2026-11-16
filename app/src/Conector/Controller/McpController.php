<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use Mcp\Server\Transport\CallbackStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O servidor de recurso do conector de I.A. em `/mcp` (`docs/specs/mcp-remoto-oauth.md`, 3.3 e 8): Streamable HTTP do
 * SDK, convertido de e para PSR-7 com o `symfony/psr-http-message-bridge`. Todos os métodos chegam ao SDK, que responde
 * o POST, o OPTIONS e 405 ao GET e ao DELETE (sem sessão MCP, 3.1).
 *
 * A rota é `stateless: true` (decisão 8 do PR 5), além do firewall `conector_mcp` sem sessão: nenhum cookie sai daqui
 * (12.18). Quem confere o token e responde 401 e 403 é o SDK, pelo validador do conector; o firewall só autentica.
 *
 * `X-Accel-Buffering: no` em toda resposta, como o chat (3.3): quando o SDK responde em fluxo (SSE), o nginx não o
 * segura. O corpo em fluxo do SDK ({@see CallbackStream}) escreve direto na saída, então só ele vira `StreamedResponse`.
 */
final class McpController extends AbstractController
{
    public function __construct(
        private readonly ServidorMcpDoConector $servidor,
        private readonly ServerRequestFactoryInterface $pedidos,
        private readonly StreamFactoryInterface $fluxos,
        private readonly UploadedFileFactoryInterface $arquivos,
        private readonly ResponseFactoryInterface $respostas,
    ) {
    }

    #[Route(ServidorDeAutorizacao::CAMINHO_MCP, name: ServidorDeAutorizacao::ROTA_MCP, stateless: true)]
    public function mcp(Request $request): Response
    {
        $pedido = (new PsrHttpFactory($this->pedidos, $this->fluxos, $this->arquivos, $this->respostas))->createRequest($request);
        $respostaDoSdk = $this->servidor->responder($pedido);

        $resposta = (new HttpFoundationFactory())->createResponse($respostaDoSdk, $respostaDoSdk->getBody() instanceof CallbackStream);
        $resposta->headers->set('X-Accel-Buffering', 'no');

        return $resposta;
    }
}
