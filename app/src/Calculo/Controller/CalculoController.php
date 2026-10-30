<?php

declare(strict_types=1);

namespace App\Calculo\Controller;

use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\ErroDoCalculo;
use App\Calculo\Service\ApresentacaoDoCalculo;
use App\Calculo\UseCase\AtualizarIndicesUseCase;
use App\Calculo\UseCase\CalcularAtualizacaoUseCase;
use App\Calculo\UseCase\ConsultarStatusDosIndicesUseCase;
use App\Calculo\UseCase\GerarPdfDoCalculoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cálculo de atualização monetária (desenho 14). Não grava nada: a tela guarda o formulário no navegador e
 * o servidor só calcula, atualiza os índices globais e gera o PDF. Toda rota exige `modules.calculo.view`.
 */
#[Route('/calculo')]
final class CalculoController extends AbstractController
{
    private const MODULO = 'calculo';
    private const TOKEN = 'calculo';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {
    }

    #[Route('', name: 'calculo_index', methods: ['GET'])]
    public function index(ConsultarStatusDosIndicesUseCase $status): Response
    {
        $this->assertAcesso();

        return $this->render('calculo/index.html.twig', ['status' => $status->executar()]);
    }

    #[Route('/calcular', name: 'calculo_calcular', methods: ['POST'])]
    public function calcular(Request $request, CalcularAtualizacaoUseCase $useCase, ApresentacaoDoCalculo $apresentacao): JsonResponse
    {
        $this->assertAcesso();
        $this->assertToken($request->headers->get('X-CSRF-Token'));

        $entrada = CalculoInput::fromArray($this->corpoJson($request));
        $resultado = $useCase->executar($entrada);
        if ($resultado instanceof ErroDoCalculo) {
            return $this->json(['ok' => false, 'erros' => [$resultado->paraTela()]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true] + $apresentacao->paraTela($entrada, $resultado));
    }

    #[Route('/indices/atualizar', name: 'calculo_indices_atualizar', methods: ['POST'])]
    public function atualizarIndices(Request $request, AtualizarIndicesUseCase $useCase): JsonResponse
    {
        $this->assertAcesso();
        $this->assertToken($request->headers->get('X-CSRF-Token'));

        $retorno = $useCase->executar();

        return $this->json(['ok' => $retorno['erro'] === '', 'status' => $retorno['status'], 'erro' => $retorno['erro']]);
    }

    #[Route('/pdf', name: 'calculo_pdf', methods: ['POST'])]
    public function pdf(Request $request, GerarPdfDoCalculoUseCase $useCase): Response
    {
        $this->assertAcesso();
        $this->assertToken((string) $request->request->get('_token'));

        $dados = json_decode((string) $request->request->get('dados', '{}'), true);
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || !$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        $retorno = $useCase->executar(CalculoInput::fromArray(\is_array($dados) ? $dados : []), $usuario, $tenant);
        if ($retorno instanceof ErroDoCalculo) {
            return new Response($retorno->mensagem, Response::HTTP_UNPROCESSABLE_ENTITY, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $resposta = new Response($retorno['conteudo'], Response::HTTP_OK, ['Content-Type' => 'application/pdf', 'X-Robots-Tag' => 'noindex']);
        $resposta->headers->set('Content-Disposition', $resposta->headers->makeDisposition('attachment', $retorno['nome'], (string) preg_replace('/[^\x20-\x7E]|["%\\\\\/]/', '_', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $retorno['nome']))));

        return $resposta;
    }

    private function assertAcesso(): void
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || !$this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function assertToken(?string $token): void
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    /** @return array<string, mixed> */
    private function corpoJson(Request $request): array
    {
        $dados = json_decode($request->getContent(), true);

        return \is_array($dados) ? $dados : [];
    }
}
