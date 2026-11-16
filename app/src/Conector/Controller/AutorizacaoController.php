<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\DTO\AutorizarConexaoInput;
use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ClienteRecusado;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Form\AutorizarConexaoType;
use App\Conector\Service\PedidoDeAutorizacaoNaSessao;
use App\Conector\Service\RespostaDeAutorizacao;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\AutorizarConexaoUseCase;
use App\Conector\UseCase\ListarEscritoriosParaConectarUseCase;
use App\Conector\UseCase\ValidarPedidoDeAutorizacaoUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A tela de autorização do conector de I.A., `/oauth/authorize` (`docs/specs/mcp-remoto-oauth.md`, 3.2, 3.3, 4 e 9.4),
 * no firewall de sessão: é onde a pessoa faz login, escolhe o escritório e diz o que o app pode fazer.
 *
 * - GET: confere o pedido ({@see ValidarPedidoDeAutorizacaoUseCase}). Cliente ou retorno recusado vira página de erro,
 *   SEM redirecionar; erro de protocolo volta ao app por redirecionamento, com `state` e `iss`. Pedido bom fica na
 *   sessão ({@see PedidoDeAutorizacaoNaSessao}) e a tela mostra os escritórios da pessoa.
 * - POST: lê o pedido SÓ da sessão (uso único), exige o CSRF com estado e manda o escritório escolhido ao
 *   {@see AutorizarConexaoUseCase}, que o confere no servidor.
 *
 * A rota está fora do validador de escritório da sessão, dos Termos não (quem não aceitou aceita antes) e roda sem o
 * filtro de escritório da sessão: o escritório escolhido pode não ser o da sessão (listas do commit 0 do PR 3).
 */
#[IsGranted('ROLE_USER')]
final class AutorizacaoController extends AbstractController
{
    public const PEDIDO_INVALIDO = 'Este pedido de conexão não vale mais. Volte ao aplicativo de I.A. e conecte de novo.';

    public function __construct(
        private readonly ValidarPedidoDeAutorizacaoUseCase $validar,
        private readonly ListarEscritoriosParaConectarUseCase $listarEscritorios,
        private readonly AutorizarConexaoUseCase $autorizar,
        private readonly PedidoDeAutorizacaoNaSessao $pedidos,
    ) {
    }

    #[Route('/oauth/authorize', name: ServidorDeAutorizacao::ROTA_AUTORIZAR, methods: ['GET', 'POST'])]
    public function autorizar(Request $request): Response
    {
        $pessoa = $this->getUser();
        if (!$pessoa instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $request->isMethod('POST') ? $this->decidir($request, $pessoa) : $this->mostrar($request, $pessoa);
    }

    private function mostrar(Request $request, User $pessoa): Response
    {
        $query = $request->query->all();
        try {
            $pedido = $this->validar->executar($query);
        } catch (ClienteRecusado) {
            return $this->recusa(ClienteRecusado::MENSAGEM, Response::HTTP_BAD_REQUEST);
        } catch (ErroOAuth $erro) {
            // Só chega aqui com o cliente e o `redirect_uri` já aceitos pelo registro (ordem da RFC 6749 4.1.2.1).
            return RespostaDeAutorizacao::erro((string) $query['redirect_uri'], $erro, \is_string($query['state'] ?? null) ? $query['state'] : null);
        }

        $input = new AutorizarConexaoInput();
        $input->pedido = $this->pedidos->guardar($pedido);
        $form = $this->createForm(AutorizarConexaoType::class, $input, ['action' => $this->generateUrl(ServidorDeAutorizacao::ROTA_AUTORIZAR)]);

        return $this->tela($pedido, $form, $pessoa);
    }

    private function decidir(Request $request, User $pessoa): Response
    {
        $input = new AutorizarConexaoInput();
        $form = $this->createForm(AutorizarConexaoType::class, $input);
        $form->handleRequest($request);
        $pedido = $this->pedidos->retirar($input->pedido);
        if ($pedido === null || !$form->isSubmitted() || !$form->isValid()) {
            return $this->recusa(self::PEDIDO_INVALIDO, Response::HTTP_BAD_REQUEST);
        }
        if (!self::clicou($form, 'autorizar')) {
            return RespostaDeAutorizacao::erro($pedido->redirectUri, ErroOAuth::accessDenied(), $pedido->state);
        }

        try {
            $codigo = $this->autorizar->executar($input, $pedido, $pessoa);
        } catch (AcessoDoConectorRecusado $recusa) {
            return $this->recusa($recusa->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (ClienteRecusado) {
            return $this->recusa(ClienteRecusado::MENSAGEM, Response::HTTP_BAD_REQUEST);
        }

        return RespostaDeAutorizacao::sucesso($pedido->redirectUri, $codigo, $pedido->state);
    }

    private function tela(PedidoDeAutorizacao $pedido, FormInterface $form, User $pessoa): Response
    {
        return $this->semMoldura($this->render('conector/autorizar.html.twig', [
            'form' => $form->createView(),
            'hostDoCliente' => $pedido->hostDoCliente(),
            'hostDoRedirect' => $pedido->hostDoRedirect(),
            'voltaParaLoopback' => $pedido->voltaParaLoopback(),
            'pediuEscrita' => $pedido->pediuEscrita(),
            'escritorios' => $this->listarEscritorios->executar($pessoa),
            'email' => (string) $pessoa->getEmail(),
        ]));
    }

    private function recusa(string $mensagem, int $status): Response
    {
        return $this->semMoldura($this->render('conector/autorizacao_recusada.html.twig', ['mensagem' => $mensagem], new Response('', $status)));
    }

    /** A tela não vai para cache nem abre dentro de moldura de outro site (clickjacking no consentimento). */
    private function semMoldura(Response $resposta): Response
    {
        $resposta->headers->set('Cache-Control', 'no-store');
        $resposta->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $resposta;
    }

    private static function clicou(FormInterface $form, string $botao): bool
    {
        $campo = $form->get($botao);

        return $campo instanceof ClickableInterface && $campo->isClicked();
    }
}
