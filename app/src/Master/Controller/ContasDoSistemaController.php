<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Master\UseCase\ReativarContaUseCase;
use App\Master\UseCase\SuspenderContaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ações do Master sobre uma conta, no menu de "Contas e escritórios" (spec docs/specs/perfis-1.2-contas.md
 * C1 a C3): suspender e reativar. Exclusivo do Master (ROLE_SUPER_ADMIN; os outros recebem 403).
 *
 * Responde JSON porque o painel (public/js/master/painel.js) é montado no navegador: ele troca a
 * situação da linha sem recarregar. CSRF por conta: o token `master_conta_<id>` vem na lista de
 * /master/dados/contas ({@see PainelMasterDadosController::contas()}) e não serve para outra conta.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/contas/{id}', requirements: ['id' => '\d+'])]
final class ContasDoSistemaController extends AbstractController
{
    public const PREFIXO_CSRF = 'master_conta_';

    #[Route('/suspender', name: 'master_conta_suspender', methods: ['POST'])]
    public function suspender(int $id, Request $request, SuspenderContaUseCase $suspender): JsonResponse
    {
        return $this->executar($id, $request, static fn (SituacaoDaContaInput $input): ContaAlteradaOutput => $suspender->executar($input), 'Conta "%s" suspensa.');
    }

    #[Route('/reativar', name: 'master_conta_reativar', methods: ['POST'])]
    public function reativar(int $id, Request $request, ReativarContaUseCase $reativar): JsonResponse
    {
        return $this->executar($id, $request, static fn (SituacaoDaContaInput $input): ContaAlteradaOutput => $reativar->executar($input), 'Conta "%s" reativada.');
    }

    /**
     * @param callable(SituacaoDaContaInput): ContaAlteradaOutput $acao
     */
    private function executar(int $id, Request $request, callable $acao, string $mensagem): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::PREFIXO_CSRF . $id, $request->request->getString('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $conta = $acao(new SituacaoDaContaInput($this->ator(), $id));
        } catch (ContaNaoEncontrada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (OperacaoDeContaRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['mensagem' => sprintf($mensagem, $conta->nome), 'conta' => ['id' => $conta->id, 'situacao' => $conta->situacao]]);
    }

    private function ator(): User
    {
        $ator = $this->getUser();
        if (!$ator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $ator;
    }
}
