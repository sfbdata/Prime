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
use App\Cobranca\Enum\Periodicidade;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\GeradorParcelamento;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Cobranca\UseCase\MarcarAcordoCumpridoUseCase;
use App\Cobranca\UseCase\MontarDetalheAcordoUseCase;
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
        private readonly GeradorParcelamento $geradorParcelamento,
        private readonly MontarDetalheAcordoUseCase $montarDetalheAcordo,
    ) {
    }

    /**
     * Detalhe do Acordo (Ajuste 7, Fatia 3): parcelas (com o quanto já foi pago), obrigações
     * substituídas, entrada, total negociado e desconto/juros derivado. LEITURA — gate só de MÓDULO
     * (como o Painel/Detalhe), resolução tenant-safe → 404 anti-IDOR, sem CSRF.
     */
    #[Route('/acordos/{id}', name: 'cobranca_acordo_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acordo = $this->acordoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acordo === null) {
            throw $this->createNotFoundException('Acordo não encontrado.');
        }

        return $this->render('cobranca/acordo/show.html.twig', [
            'acordo' => $this->montarDetalheAcordo->executar($acordo, $tenant),
        ]);
    }

    /**
     * Prévia ao vivo do parcelamento (Ajuste 7), fonte ÚNICA da aritmética de centavos — o gerador
     * inteligente do modal de criar acordo consome este endpoint. GET read-only (sem CSRF); não toca
     * banco (cálculo puro), só exige a capacidade de gerenciar. `total`/`entrada` em CENTAVOS.
     */
    #[Route('/acordos/previa-parcelamento', name: 'cobranca_acordo_previa_parcelamento', methods: ['GET'])]
    public function previaParcelamento(Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->json(['ok' => false, 'erro' => 'Sem acesso.'], Response::HTTP_FORBIDDEN);
        }

        $periodicidade = Periodicidade::tryFrom((string) $request->query->get('periodicidade'));
        $data1 = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('data1'));
        // createFromFormat rola datas inválidas por overflow (31/02 → 03/03); rejeita explicitamente.
        $dataValida = $data1 !== false && (\DateTimeImmutable::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0])['warning_count'] === 0;

        if ($periodicidade === null || !$dataValida) {
            return $this->json(['ok' => false, 'erro' => 'Parâmetros do parcelamento inválidos.']);
        }

        try {
            $linhas = $this->geradorParcelamento->gerar(
                $request->query->getInt('total'),
                max(0, $request->query->getInt('entrada')),
                $request->query->getInt('qtd'),
                $data1,
                $periodicidade,
            );
        } catch (ParcelamentoInvalidoException $e) {
            return $this->json(['ok' => false, 'erro' => $e->getMessage()]);
        }

        return $this->json([
            'ok' => true,
            'parcelas' => array_map(static fn ($l): array => [
                'descricao' => $l->descricao,
                'valor' => $l->valor,
                'vencimento' => $l->vencimento->format('Y-m-d'),
            ], $linhas),
        ]);
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
            } catch (CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | ObrigacaoJaSubstituidaException | ParcelamentoInvalidoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
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
        $objetoId = $this->objetoIdDoCaso($acordo->getCaso());

        if (!$this->isCsrfTokenValid('marcar_cumprido_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
        }

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = $id;
        try {
            $this->marcarCumprido->executar($input, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Acordo marcado como cumprido.');
        } catch (AcordoNaoAtivoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
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
        $objetoId = $this->objetoIdDoCaso($acordo->getCaso());

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

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
    }
}
