<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\DTO\CompartilhamentosDaPastaOutput;
use App\Pasta\DTO\CompartilharPastaInput;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\UseCase\CompartilharPastaComAEquipeUseCase;
use App\Pasta\UseCase\ConsultarCompartilhamentosDaPastaUseCase;
use App\Pasta\UseCase\EncerrarCompartilhamentoDaPastaUseCase;
use App\Pasta\UseCase\RepublicarPastaParaOutraContaUseCase;
use App\Service\Tenant\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Compartilhar pasta com a equipe do escritório ou republicar para outra conta pelo código (spec
 * acesso-restrito-e-compartilhar §4.4 e §4.5; desenho "03 - EXPEDIENTE", L3, L4, L5 e L6). Só JSON, para a janela e o menu da Lista. O GET devolve o token que os POSTs usam
 * (`pasta_compartilhar_<id>`). A barreira do cadeado e a do escopo olham a pasta da rota antes da action.
 */
#[Route('/pasta/{id}/compartilhamento', requirements: ['id' => '\d+'])]
final class PastaCompartilhamentoController extends AbstractController
{
    private const TOKEN = 'pasta_compartilhar_';

    public function __construct(
        private readonly ConsultarCompartilhamentosDaPastaUseCase $consultar,
        private readonly CompartilharPastaComAEquipeUseCase $compartilhar,
        private readonly EncerrarCompartilhamentoDaPastaUseCase $encerrar,
        private readonly RepublicarPastaParaOutraContaUseCase $republicar,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'pasta_compartilhamento_ver', methods: ['GET'])]
    #[PastaPorId('id')]
    public function ver(int $id): JsonResponse
    {
        return $this->acao(function (User $quem, Tenant $tenant) use ($id): JsonResponse {
            $saida = $this->consultar->executar($id, $quem, $tenant);

            return $this->json($saida->paraJson() + ['token' => $this->container->get('security.csrf.token_manager')->getToken(self::TOKEN . $id)->getValue()]);
        });
    }

    #[Route('', name: 'pasta_compartilhamento_criar', methods: ['POST'])]
    #[PastaPorId('id')]
    public function criar(int $id, Request $request): JsonResponse
    {
        return $this->acaoComToken($id, $request, function (User $quem, Tenant $tenant) use ($id, $request): JsonResponse {
            $input = CompartilharPastaInput::doJson($request->toArray());
            if ($input->outraConta) {
                $convite = $this->republicar->executar($id, $input, $quem, $tenant);
                $nome = CompartilhamentosDaPastaOutput::pessoa($convite->getDestino())['nome'];

                return $this->json(['ok' => true, 'nome' => $nome, 'mensagem' => sprintf('Pasta republicada. %s recebe o convite e passa a colaborar quando aceitar.', $nome)]);
            }
            $compartilhamento = $this->compartilhar->executar($id, $input, $quem, $tenant);
            $nome = CompartilhamentosDaPastaOutput::pessoa($compartilhamento->getDestino())['nome'];

            return $this->json(['ok' => true, 'nome' => $nome, 'mensagem' => sprintf('Pasta compartilhada com %s. Ela já aparece para ele, inclusive no acesso restrito.', $nome)]);
        });
    }

    #[Route('/{compartilhamentoId}/encerrar', name: 'pasta_compartilhamento_encerrar', requirements: ['compartilhamentoId' => '\d+'], methods: ['POST'])]
    #[PastaPorId('id')]
    public function encerrar(int $id, int $compartilhamentoId, Request $request): JsonResponse
    {
        return $this->acaoComToken($id, $request, function (User $quem, Tenant $tenant) use ($id, $compartilhamentoId): JsonResponse {
            $this->encerrar->executar($id, $compartilhamentoId, $quem, $tenant);

            return $this->json(['ok' => true]);
        });
    }

    private function acaoComToken(int $id, Request $request, callable $executar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN . $id, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        return $this->acao($executar);
    }

    private function acao(callable $executar): JsonResponse
    {
        $quem   = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$quem instanceof User || $tenant === null) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        try {
            return $executar($quem, $tenant);
        } catch (CompartilhamentoRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], $e->ehDaPessoa() ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            return $this->json(['erro' => CompartilhamentoRecusadoException::JA_COMPARTILHADA], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
