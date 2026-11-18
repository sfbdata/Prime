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
    public const ESCOLHA_O_ESCRITORIO = 'Escolha o escritório que o aplicativo vai acessar.';
    /** O `error_description` que volta ao app quando o escritório ou a pessoa foi recusado no POST. */
    public const ACESSO_RECUSADO = 'O acesso a este escritório não está disponível.';

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
        $valido = $form->isSubmitted() && $form->isValid();

        // Autorizar sem escolher o escritório (o CSRF já foi conferido acima): volta à tela com o aviso, e o pedido
        // fica na sessão para a segunda tentativa.
        if ($valido && $input->tenantId === null && self::clicou($form, 'autorizar')) {
            $pendente = $this->pedidos->ler($input->pedido);
            if ($pendente !== null) {
                return $this->tela($pendente, $form, $pessoa, self::ESCOLHA_O_ESCRITORIO, Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $pedido = $this->pedidos->retirar($input->pedido);
        if ($pedido === null || !$valido) {
            // O mesmo pedido de novo, logo depois de conectar (segundo clique enquanto o app carregava): a conexão já
            // foi feita, e o navegador mostra esta resposta no lugar da volta ao app. Nada é concedido de novo.
            if ($valido && $this->pedidos->foiConcluido($input->pedido)) {
                return $this->semMoldura($this->render('conector/autorizacao_concluida.html.twig'));
            }

            return $this->recusa(self::PEDIDO_INVALIDO, Response::HTTP_BAD_REQUEST);
        }
        if (!self::clicou($form, 'autorizar')) {
            return RespostaDeAutorizacao::erro($pedido->redirectUri, ErroOAuth::accessDenied(), $pedido->state);
        }

        try {
            $codigo = $this->autorizar->executar($input, $pedido, $pessoa);
        } catch (AcessoDoConectorRecusado) {
            // O pedido já passou pelo registro, então o retorno é seguro: o app recebe a recusa em vez de ficar
            // esperando. O motivo detalhado não sai do BlueJus (a tela do GET já o mostrou ao lado do escritório).
            return RespostaDeAutorizacao::erro($pedido->redirectUri, ErroOAuth::accessDenied(self::ACESSO_RECUSADO), $pedido->state);
        } catch (ClienteRecusado) {
            // Cliente ou retorno que deixou de valer: nunca redireciona.
            return $this->recusa(ClienteRecusado::MENSAGEM, Response::HTTP_BAD_REQUEST);
        }

        $this->pedidos->marcarConcluido($input->pedido);

        return RespostaDeAutorizacao::sucesso($pedido->redirectUri, $codigo, $pedido->state);
    }

    private function tela(PedidoDeAutorizacao $pedido, FormInterface $form, User $pessoa, ?string $aviso = null, int $status = Response::HTTP_OK): Response
    {
        return $this->semMoldura($this->render('conector/autorizar.html.twig', [
            'form' => $form->createView(),
            'hostDoCliente' => $pedido->hostDoCliente(),
            'hostDoRedirect' => $pedido->hostDoRedirect(),
            'voltaParaLoopback' => $pedido->voltaParaLoopback(),
            'pediuEscrita' => $pedido->pediuEscrita(),
            'escritorios' => $this->listarEscritorios->executar($pessoa),
            'email' => (string) $pessoa->getEmail(),
            'aviso' => $aviso,
        ], new Response('', $status)));
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
