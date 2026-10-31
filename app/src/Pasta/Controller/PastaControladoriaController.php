<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\AlertaDaPasta;
use App\Pasta\DTO\CadastroDaPastaInput;
use App\Pasta\DTO\CadastroDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaReuRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\AlertasDaPasta;
use App\Pasta\Service\DadosDaCobrancaNaPasta;
use App\Pasta\UseCase\SalvarCadastroDaPastaUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Controladoria da pasta (desenho 02 de 31/10/2026): alertas e Cadastro da pasta, carregados pelo botão ao abrir a pasta. */
#[Route('/pasta')]
final class PastaControladoriaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AcessoAPasta $acesso,
        private readonly AlertasDaPasta $alertas,
        private readonly PastaReuRepository $reus,
        private readonly DadosDaCobrancaNaPasta $cobranca,
        private readonly SalvarCadastroDaPastaUseCase $salvarCadastro,
    ) {
    }

    public static function idDoToken(int $pastaId): string
    {
        return 'pasta_controladoria_' . $pastaId;
    }

    #[Route('/{id}/controladoria', name: 'pasta_controladoria', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function alertas(Pasta $pasta): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant || !$this->acesso->podeVer($user, $pasta)) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }

        $lista = $this->alertas->montar($pasta, $tenant, $user, new \DateTimeImmutable());
        $reu = $this->reus->daPasta($pasta, $tenant)[0] ?? null;

        return $this->json([
            'alertas' => array_map(static fn (AlertaDaPasta $a): array => $a->paraArray(), $lista),
            'urgentes' => count(array_filter($lista, static fn (AlertaDaPasta $a): bool => $a->nivel !== AlertaDaPasta::BAIXA)),
            'cadastro' => CadastroDaPastaOutput::de($pasta, $reu, $this->cobranca->da($pasta, $tenant))->paraArray(),
            'podeEditar' => $this->acesso->podeEditar($user, $pasta) && !$pasta->estaExcluida(),
        ]);
    }

    #[Route('/{id}/controladoria/cadastro', name: 'pasta_controladoria_cadastro', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvarCadastro(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->isCsrfTokenValid(self::idDoToken((int) $pasta->getId()), (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $completo = $this->salvarCadastro->executar($pasta, $user, $tenant, $this->entrada($request));
        } catch (PastaDeOutroEscritorioException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (SemPermissaoParaEditarPastaException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'completo' => $completo]);
    }

    private function entrada(Request $request): CadastroDaPastaInput
    {
        $campo = static fn (string $nome): string => (string) $request->request->get($nome, '');

        return new CadastroDaPastaInput(
            $campo('reu'), $campo('cpf'), $campo('qualificacao'), $campo('email'), $campo('endereco'),
            $campo('contrato'), $campo('unidade'), $campo('titular_extrato'), $campo('prescricao'),
        );
    }
}
