<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\MarcarAcordoCumpridoInput;
use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Cobranca\UseCase\MarcarAcordoCumpridoUseCase;
use App\Cobranca\UseCase\RomperAcordoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações de Acordos do Caso (Onda 8B): criar, romper, cancelar, marcar cumprido. Controller FINO —
 * gate módulo + capacidade `resources.cobranca.gerenciar`, resolução tenant-safe (anti-IDOR → 404),
 * Form → UseCase, PRG sempre. As obrigações substituíveis do "criar" são escopadas ao Caso.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class AcordoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly AcordoRepository $acordoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CriarAcordoUseCase $criarAcordo,
        private readonly RomperAcordoUseCase $romperAcordo,
        private readonly CancelarAcordoUseCase $cancelarAcordo,
        private readonly MarcarAcordoCumpridoUseCase $marcarCumprido,
    ) {
    }

    #[Route('/casos/{id}/acordos', name: 'cobranca_acordo_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function criar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new CriarAcordoInput();
        $input->casoId = $id;
        $opcoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));
        $form = $this->createForm(AcordoCriarType::class, $input, ['obrigacoes' => $opcoes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->criarAcordo->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Acordo criado.');
            } catch (CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | ObrigacaoJaSubstituidaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/acordos/{id}/romper', name: 'cobranca_acordo_romper', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function romper(int $id, Request $request): Response
    {
        $input = new RomperAcordoInput();
        $input->acordoId = $id;

        return $this->mutarAcordoComMotivo($id, $request, $input, RomperAcordoType::class, fn ($i, $t, $u) => $this->romperAcordo->executar($i, $t, $u), 'Acordo rompido.');
    }

    #[Route('/acordos/{id}/cancelar', name: 'cobranca_acordo_cancelar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancelar(int $id, Request $request): Response
    {
        $input = new CancelarAcordoInput();
        $input->acordoId = $id;

        return $this->mutarAcordoComMotivo($id, $request, $input, CancelarAcordoType::class, fn ($i, $t, $u) => $this->cancelarAcordo->executar($i, $t, $u), 'Acordo cancelado.');
    }

    #[Route('/acordos/{id}/cumprir', name: 'cobranca_acordo_cumprir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cumprir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acordo = $this->acordoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acordo === null) {
            throw $this->createNotFoundException('Acordo não encontrado.');
        }
        $casoId = $acordo->getCaso()->getId();

        if (!$this->isCsrfTokenValid('marcar_cumprido_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
        }

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = $id;
        try {
            $this->marcarCumprido->executar($input, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Acordo marcado como cumprido.');
        } catch (AcordoNaoAtivoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
    }

    /**
     * Fluxo comum de romper/cancelar (per-acordo, com motivo): gate + resolução tenant-safe + Form +
     * PRG. `$input` (com acordoId setado) alimenta o Form; `$executar($input, $tenant, $user)` chama
     * o UseCase específico.
     */
    private function mutarAcordoComMotivo(int $id, Request $request, object $input, string $tipoForm, callable $executar, string $sucesso): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acordo = $this->acordoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acordo === null) {
            throw $this->createNotFoundException('Acordo não encontrado.');
        }
        $casoId = $acordo->getCaso()->getId();

        $form = $this->createForm($tipoForm, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', $sucesso);
            } catch (AcordoNaoAtivoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
    }
}
