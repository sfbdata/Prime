<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\CriarPessoaInput;
use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\DTO\VincularPessoaAObjetoInput;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Exception\VinculoJaEncerradoException;
use App\Cobranca\Exception\VinculoNaoEncontradoException;
use App\Cobranca\Form\CriarPessoaType;
use App\Cobranca\Form\EncerrarVinculoType;
use App\Cobranca\Form\VincularPessoaAObjetoType;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\CriarPessoaUseCase;
use App\Cobranca\UseCase\EncerrarVinculoUseCase;
use App\Cobranca\UseCase\VincularPessoaAObjetoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações de Pessoa e Vínculo (Onda 8B-E). Controller FINO: gate módulo + capacidade
 * `resources.cobranca.gerenciar`, resolução tenant-safe por id (anti-IDOR → 404), Form → UseCase,
 * PRG sempre. Nenhuma regra de negócio aqui — os UseCases fazem flush internamente.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class PessoaController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PessoaRepository $pessoaRepository,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly VinculoPessoaObjetoRepository $vinculoRepository,
        private readonly CriarPessoaUseCase $criarPessoa,
        private readonly VincularPessoaAObjetoUseCase $vincularPessoa,
        private readonly EncerrarVinculoUseCase $encerrarVinculo,
    ) {
    }

    #[Route('/pessoas', name: 'cobranca_pessoa_criar', methods: ['POST'])]
    public function criar(Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $input = new CriarPessoaInput();
        $form = $this->createForm(CriarPessoaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->criarPessoa->executar($input, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Pessoa cadastrada.');
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirecionarParaOrigem($request);
    }

    #[Route('/objetos/{id}/vinculos', name: 'cobranca_vinculo_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function vincular(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }
        $carteiraId = $objeto->getCarteira()?->getId();

        $input = new VincularPessoaAObjetoInput();
        $input->objetoId = $id;
        $form = $this->createForm(VincularPessoaAObjetoType::class, $input, ['pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant)]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->vincularPessoa->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pessoa vinculada ao objeto.');
            } catch (PessoaNaoEncontradaException | ObjetoNaoEncontradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $carteiraId]);
    }

    #[Route('/vinculos/{id}/encerrar', name: 'cobranca_vinculo_encerrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function encerrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $vinculo = $this->vinculoRepository->findOneByIdDoTenant($id, $tenant);
        if ($vinculo === null) {
            throw $this->createNotFoundException('Vínculo não encontrado.');
        }
        $carteiraId = $vinculo->getObjeto()?->getCarteira()?->getId();

        $input = new EncerrarVinculoInput();
        $input->vinculoId = $id;
        $form = $this->createForm(EncerrarVinculoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->encerrarVinculo->executar($input, $tenant);
                $this->addFlash('success', 'Vínculo encerrado.');
            } catch (VinculoNaoEncontradoException | VinculoJaEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_carteira_show', ['id' => $carteiraId]);
    }

    /**
     * Volta para a tela de origem quando o Referer é do próprio host (o cadastro de pessoa é acionado
     * de várias telas); caso contrário, cai na lista de carteiras. Mantém o PRG simples e testável.
     */
    private function redirecionarParaOrigem(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('cobranca_carteira_index');
    }
}
