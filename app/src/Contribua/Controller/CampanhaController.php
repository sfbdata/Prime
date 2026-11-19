<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Exception\CampanhaItemNaoEncontrado;
use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\UseCase\CancelarInscricaoDaCampanhaUseCase;
use App\Contribua\UseCase\ConfigurarCampanhaUseCase;
use App\Contribua\UseCase\ConfirmarPagamentoDaCampanhaUseCase;
use App\Contribua\UseCase\InscreverNaCampanhaUseCase;
use App\Contribua\UseCase\MarcarDevolucaoDaCampanhaComoPagaUseCase;
use App\Contribua\UseCase\MontarJanelaDaCampanhaUseCase;
use App\Contribua\UseCase\PedirDevolucaoDaCampanhaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modo campanha "BlueJus Black" (Desafio): a janela, a inscrição, o pedido de devolução e a configuração do Master.
 * As ações são JSON (`{ok, mensagem}` ou `{ok: false, erro}`), com o CSRF `contribua` no campo `_token`.
 */
#[IsGranted('ROLE_USER')]
#[Route('/contribua/campanha')]
final class CampanhaController extends AbstractController
{
    private const CSRF = 'contribua';

    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
    ) {
    }

    #[Route('/janela', name: 'contribua_campanha_janela', methods: ['GET'])]
    public function janela(MontarJanelaDaCampanhaUseCase $montar): Response
    {
        $janela = $montar->executar($this->sessao->atual());
        if ($janela === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('contribua/campanha/_janela.html.twig', ['janela' => $janela]);
    }

    #[Route('/inscrever', name: 'contribua_campanha_inscrever', methods: ['POST'])]
    public function inscrever(Request $request, InscreverNaCampanhaUseCase $inscrever): JsonResponse
    {
        return $this->acao($request, fn (): string => $inscrever->executar(
            $this->sessao->atual(),
            $this->campo($request, 'nome'),
            $this->campo($request, 'cpf'),
            $this->campo($request, 'oab'),
            $this->campo($request, 'aceite') === '1',
            $request->getClientIp(),
        ));
    }

    #[Route('/devolucao', name: 'contribua_campanha_devolucao', methods: ['POST'])]
    public function devolucao(Request $request, PedirDevolucaoDaCampanhaUseCase $pedir): JsonResponse
    {
        return $this->acao($request, fn (): string => $pedir->executar($this->sessao->atual(), $this->campo($request, 'pix'), $this->campo($request, 'motivo')));
    }

    #[Route('/configurar', name: 'contribua_campanha_configurar', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function configurar(Request $request, ConfigurarCampanhaUseCase $configurar): JsonResponse
    {
        return $this->acao($request, function () use ($request, $configurar): string {
            $campos = $request->request->all();
            unset($campos['_token']);

            return $configurar->executar($this->sessao->usuario(), $campos);
        });
    }

    #[Route('/padrao', name: 'contribua_campanha_padrao', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function padrao(Request $request, ConfigurarCampanhaUseCase $configurar): JsonResponse
    {
        return $this->acao($request, fn (): string => $configurar->restaurarPadrao($this->sessao->usuario()));
    }

    #[Route('/participacoes/{id}/confirmar', name: 'contribua_campanha_confirmar', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function confirmar(int $id, Request $request, ConfirmarPagamentoDaCampanhaUseCase $confirmar): JsonResponse
    {
        return $this->acao($request, fn (): string => $confirmar->executar($this->sessao->usuario(), $id));
    }

    #[Route('/participacoes/{id}/cancelar', name: 'contribua_campanha_cancelar', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function cancelar(int $id, Request $request, CancelarInscricaoDaCampanhaUseCase $cancelar): JsonResponse
    {
        return $this->acao($request, fn (): string => $cancelar->executar($id));
    }

    #[Route('/devolucoes/{id}/devolvida', name: 'contribua_campanha_devolvida', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function devolvida(int $id, Request $request, MarcarDevolucaoDaCampanhaComoPagaUseCase $marcar): JsonResponse
    {
        return $this->acao($request, fn (): string => $marcar->executar($this->sessao->usuario(), $id));
    }

    /** CSRF, a ação e a resposta no formato que o JS lê: `{ok, mensagem}`, `{ok: false, erro}` (403 CSRF, 404 e 422). */
    private function acao(Request $request, callable $acao): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json(['ok' => true, 'mensagem' => $acao()]);
        } catch (CampanhaItemNaoEncontrado $naoExiste) {
            return $this->json(['ok' => false, 'erro' => $naoExiste->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\DomainException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function campo(Request $request, string $nome): string
    {
        return (string) $request->request->get($nome, '');
    }
}
