<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\Apoio;
use App\Contribua\Enum\TipoDeSugestao;
use App\Contribua\Service\CodigoPixDaConta;
use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\UseCase\AtenderSugestaoUseCase;
use App\Contribua\UseCase\EnviarSugestaoUseCase;
use App\Contribua\UseCase\MontarPaginaDoContribuaUseCase;
use App\Contribua\UseCase\MontarPagamentoDoContribuaUseCase;
use App\Contribua\UseCase\PedirEspacoUseCase;
use App\Contribua\UseCase\RegistrarApoioUseCase;
use App\Contribua\UseCase\RegistrarMotivoDeNaoApoiarUseCase;
use App\Contribua\UseCase\RegistrarRetornoDoPagamentoUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Quem somos e contribua" (desenho 08): a página e as ações dela. Qualquer pessoa logada num
 * escritório abre; pedir espaço é do primário; atender sugestão é do Master. As ações são JSON,
 * chamadas pelo JS da página com o `X-CSRF-Token` que o `base.html.twig` injeta.
 */
#[IsGranted('ROLE_USER')]
#[Route('/contribua')]
final class ContribuaController extends AbstractController
{
    private const CSRF = 'contribua';

    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
    ) {
    }

    /** `?assinatura=ok` é a página de retorno colada no gateway: abre o "Pagamento enviado" do checkout. */
    #[Route('', name: 'contribua_index', methods: ['GET'])]
    public function index(Request $request, MontarPaginaDoContribuaUseCase $montar, MontarPagamentoDoContribuaUseCase $pagamento, RegistrarRetornoDoPagamentoUseCase $retorno): Response
    {
        $quem = $this->solicitante();
        $voltou = $request->query->get('assinatura') === 'ok' ? $retorno->executar($quem) : null;

        return $this->render('contribua/index.html.twig', ['pagina' => $montar->executar($quem), 'pagamento' => $pagamento->executar($quem), 'retorno' => $voltou]);
    }

    /** O "copia e cola" para o valor escolhido (R$ 20, 50, 100 ou outro). */
    #[Route('/pix', name: 'contribua_pix', methods: ['GET'])]
    public function pix(Request $request, CodigoPixDaConta $codigoPix): JsonResponse
    {
        $centavos = min(Apoio::VALOR_MAXIMO_CENTAVOS, max(0, (int) round((float) str_replace(',', '.', (string) $request->query->get('valor', '0')) * 100)));

        return $this->json($codigoPix->para($this->solicitante(), $centavos));
    }

    #[Route('/apoio', name: 'contribua_apoio', methods: ['POST'])]
    public function apoio(Request $request, RegistrarApoioUseCase $registrar): JsonResponse
    {
        return $this->acao($request, fn (): string => $registrar->executar($this->solicitante(), (int) $request->request->get('centavos', 0)));
    }

    #[Route('/motivo', name: 'contribua_motivo', methods: ['POST'])]
    public function motivo(Request $request, RegistrarMotivoDeNaoApoiarUseCase $registrar): JsonResponse
    {
        return $this->acao($request, function () use ($request, $registrar): string {
            $registrar->executar($this->solicitante(), (string) $request->request->get('motivo', ''));

            return 'Obrigado por contar! Sua resposta ajuda a melhorar o BlueJus.';
        });
    }

    #[Route('/sugestao', name: 'contribua_sugestao', methods: ['POST'])]
    public function sugestao(Request $request, EnviarSugestaoUseCase $enviar): JsonResponse
    {
        return $this->acao($request, function () use ($request, $enviar): string {
            $tipo = TipoDeSugestao::tryFrom((string) $request->request->get('tipo')) ?? TipoDeSugestao::Sugestao;
            $enviar->executar($this->solicitante(), $tipo, (string) $request->request->get('texto', ''));

            return 'Sugestão enviada. Obrigado!';
        });
    }

    #[Route('/sugestao/{id}/atender', name: 'contribua_sugestao_atender', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function atender(int $id, Request $request, AtenderSugestaoUseCase $atender): JsonResponse
    {
        if (!$this->csrfValido($request)) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $atendida = $atender->executar($this->usuario(), $id, $request->request->get('resposta'));
        if ($atendida === null) {
            throw $this->createNotFoundException();
        }

        return $this->json(['ok' => true, 'atendida' => $atendida]);
    }

    #[Route('/espaco/pedido', name: 'contribua_espaco_pedido', methods: ['POST'])]
    public function pedirEspaco(Request $request, PedirEspacoUseCase $pedir): JsonResponse
    {
        return $this->acao($request, fn (): string => $pedir->executar($this->solicitante(), (string) $request->request->get('plano', '')));
    }

    /** CSRF, a ação e a resposta no formato que o JS da página lê: `{ok, mensagem}` ou `{ok: false, erro}`. */
    private function acao(Request $request, callable $acao): JsonResponse
    {
        if (!$this->csrfValido($request)) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json(['ok' => true, 'mensagem' => $acao()]);
        } catch (\DomainException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function csrfValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'));
    }

    private function solicitante(): Solicitante
    {
        return $this->sessao->atual();
    }

    private function usuario(): User
    {
        return $this->sessao->usuario();
    }
}
