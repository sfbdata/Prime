<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\ContaAlteradaOutput;
use App\Master\DTO\ExcluirContaInput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\Exception\ContaNaoEncontrada;
use App\Master\Exception\OperacaoDeContaRecusada;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Master\UseCase\ExcluirContaPeloMasterUseCase;
use App\Master\UseCase\ReativarContaUseCase;
use App\Master\UseCase\SuspenderContaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ações do Master sobre uma conta, no menu de "Contas e escritórios" (spec docs/specs/perfis-1.2-contas.md
 * C1 a C4b): suspender, reativar (também da quarentena) e excluir. Exclusivo do Master (ROLE_SUPER_ADMIN; os outros recebem 403).
 *
 * Responde JSON porque o painel (public/js/master/painel.js) é montado no navegador: ele troca a
 * situação da linha sem recarregar. CSRF por conta: o token `master_conta_<id>` vem na lista de
 * /master/dados/contas ({@see PainelMasterDadosController::contas()}) e não serve para outra conta.
 *
 * Idioma (spec idioma-do-sistema, P2b): a resposta e a sessão expirada saem do catálogo (master.contas.*) no idioma
 * da requisição. A recusa dos UseCases (ContaNaoEncontrada, OperacaoDeContaRecusada, PedidoDeExclusaoRecusado do
 * Tenant) vai como está, em português, até ganhar chave própria (spec §8).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/contas/{id}', requirements: ['id' => '\d+'])]
final class ContasDoSistemaController extends AbstractController
{
    public const PREFIXO_CSRF = 'master_conta_';

    public function __construct(private readonly TranslatorInterface $tradutor)
    {
    }

    #[Route('/suspender', name: 'master_conta_suspender', methods: ['POST'])]
    public function suspender(int $id, Request $request, SuspenderContaUseCase $suspender): JsonResponse
    {
        return $this->executar($id, $request, static fn (SituacaoDaContaInput $input): ContaAlteradaOutput => $suspender->executar($input), 'master.contas.suspensa');
    }

    #[Route('/reativar', name: 'master_conta_reativar', methods: ['POST'])]
    public function reativar(int $id, Request $request, ReativarContaUseCase $reativar): JsonResponse
    {
        return $this->executar($id, $request, static fn (SituacaoDaContaInput $input): ContaAlteradaOutput => $reativar->executar($input), 'master.contas.reativada');
    }

    /** Excluir: a palavra EXCLUIR vem no corpo (`confirmacao`) e o UseCase a confere, não só o painel. */
    #[Route('/excluir', name: 'master_conta_excluir', methods: ['POST'])]
    public function excluir(int $id, Request $request, ExcluirContaPeloMasterUseCase $excluir): JsonResponse
    {
        $confirmacao = $request->request->getString('confirmacao');

        return $this->executar($id, $request, static fn (SituacaoDaContaInput $input): ContaAlteradaOutput => $excluir->executar(new ExcluirContaInput($input->ator, $input->contaId, $confirmacao)), 'master.contas.excluida');
    }

    /**
     * @param callable(SituacaoDaContaInput): ContaAlteradaOutput $acao
     */
    private function executar(int $id, Request $request, callable $acao, string $chaveDaMensagem): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::PREFIXO_CSRF . $id, $request->request->getString('_token'))) {
            return $this->json(['erro' => $this->tradutor->trans('master.contas.sessao_expirada')], Response::HTTP_FORBIDDEN);
        }

        try {
            $conta = $acao(new SituacaoDaContaInput($this->ator(), $id));
        } catch (ContaNaoEncontrada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (OperacaoDeContaRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (PedidoDeExclusaoRecusado $e) {
            // Pedido decidido ao mesmo tempo pela desistência do primário (409).
            return $this->json(['erro' => $e->getMessage()], $e->status);
        }

        $mensagem = $this->tradutor->trans($chaveDaMensagem, ['nome' => $conta->nome, 'papel' => $this->tradutor->trans('termos.master')]);

        return $this->json(['mensagem' => $mensagem, 'conta' => ['id' => $conta->id, 'situacao' => $conta->situacao, 'purgaEm' => $conta->purgaEm]]);
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
