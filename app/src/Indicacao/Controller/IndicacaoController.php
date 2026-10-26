<?php

declare(strict_types=1);

namespace App\Indicacao\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\DTO\IndicarPorEmailInput;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\Service\CodigoDeIndicacao;
use App\Indicacao\UseCase\ConferirCodigoDeIndicacaoUseCase;
use App\Indicacao\UseCase\IndicarPorEmailUseCase;
use App\Indicacao\UseCase\ObterMinhasIndicacoesUseCase;
use App\Indicacao\UseCase\RegistrarIndicacaoPorCanalUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Indicar o BlueJus" do menu do usuário (spec `indicar-bluejus.md`; janela bj-contas.js L98-133). Para todo logado,
 * com ou sem escritório: o dado é da pessoa, sem tenant. JSON; os POST exigem o token CSRF `indicacao` no header
 * `X-CSRF-Token`. Recusa (cadastro, trava, e-mail) volta 422 com o texto do desenho em `mensagem`.
 *
 * `/i/{codigo}` é pública (security.yaml): guarda o código na sessão e leva ao cadastro; código que não existe leva
 * ao cadastro igual, com a chave nula (mesma resposta, inclusive nos cabeçalhos: não revela se o código existe).
 */
final class IndicacaoController extends AbstractController
{
    public const TOKEN = 'indicacao';
    public const CHAVE_DA_SESSAO = 'indicacao_codigo';

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/indicacao', name: 'indicacao_janela', methods: ['GET'])]
    public function janela(ObterMinhasIndicacoesUseCase $obter): JsonResponse
    {
        return $this->json($obter->executar($this->usuario())->paraJson());
    }

    #[Route('/indicacao/email', name: 'indicacao_email', methods: ['POST'])]
    public function porEmail(Request $request, IndicarPorEmailUseCase $indicar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['mensagem' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $corpo = json_decode($request->getContent(), true);
        $email = is_array($corpo) && is_string($corpo['email'] ?? null) ? $corpo['email'] : '';
        $nome = is_array($corpo) && is_string($corpo['nome'] ?? null) ? $corpo['nome'] : null;

        return $this->responder(fn (): string => $indicar->executar(new IndicarPorEmailInput($email, $nome), $this->usuario(), $this->escritorioAtual()));
    }

    #[Route('/indicacao/canal/{canal}', name: 'indicacao_canal', requirements: ['canal' => 'whatsapp|link'], methods: ['POST'])]
    public function porCanal(string $canal, Request $request, RegistrarIndicacaoPorCanalUseCase $registrar): JsonResponse
    {
        if (!$this->tokenValido($request)) {
            return $this->json(['mensagem' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        return $this->responder(fn (): string => $registrar->executar(CanalDeIndicacao::from($canal), $this->usuario(), $this->escritorioAtual()));
    }

    #[Route('/i/{codigo}', name: 'indicacao_link_curto', methods: ['GET'])]
    public function linkCurto(string $codigo, Request $request, ConferirCodigoDeIndicacaoUseCase $conferir): RedirectResponse
    {
        // Sempre grava a chave (nulo quando o código não existe): a sessão nasce nos dois casos e a resposta, com o
        // Set-Cookie, é a mesma; só remover deixaria a sessão vazia e sem cookie, o que revelaria o código válido.
        $request->getSession()->set(self::CHAVE_DA_SESSAO, $conferir->executar($codigo));

        // O cupom preenchido no "Criar conta" (desenho 10 VS 25.10.26) repete o que veio na URL, conferido só no
        // formato: o destino é o mesmo para código válido e inválido.
        $cupom = CodigoDeIndicacao::doCupom($codigo);

        return $this->redirectToRoute('auth_cadastro', $cupom !== null ? ['ind' => $cupom] : []);
    }

    /** @param \Closure(): string $acao */
    private function responder(\Closure $acao): JsonResponse
    {
        try {
            return $this->json(['ok' => true, 'mensagem' => $acao()]);
        } catch (IndicacaoRecusada $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function tokenValido(Request $request): bool
    {
        return $this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''));
    }

    private function usuario(): User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : throw $this->createAccessDeniedException();
    }

    /** O escritório da sessão, só com vínculo ativo (as rotas da janela não passam pelo validador de escritório). */
    private function escritorioAtual(): ?Tenant
    {
        $vinculo = $this->tenantContext->getCurrentUserTenant();
        if ($vinculo === null || !$vinculo->isActive() || $vinculo->getTenant()->isActive() !== true) {
            return null;
        }

        return $vinculo->getTenant();
    }
}
