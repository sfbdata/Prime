<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\CriarPessoaVinculadaInput;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Form\CriarPessoaVinculadaType;
use App\Cobranca\Form\EncerrarVinculoType;
use App\Cobranca\Form\VincularPessoaAObjetoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\MontadorModaisCaso;
use App\Cobranca\UseCase\CriarPessoaVinculadaAoObjetoUseCase;
use App\Cobranca\UseCase\MontarDetalheObjetoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP da página unificada do Objeto de Cobrança (ajuste 2). Abrir o objeto = ver a cobrança
 * inteira (pessoas + obrigações + pagamentos + acordos + documentos + histórico) numa página só. O
 * `CasoCobranca` continua como âncora invisível (1 por objeto): resolvido aqui de forma tenant-safe e
 * usado para montar o corpo operacional. Controller FINO: gate de módulo, resolução anti-IDOR,
 * delegação ao UseCase de leitura e ao `MontadorModaisCaso`, render de Output DTOs.
 */
#[Route('/cobrancas/objetos')]
#[IsGranted('ROLE_USER')]
final class ObjetoController extends AbstractController
{
    use AutorizacaoCobranca;

    private const MODULO_PASTAS = 'pastas';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ObjetoCobrancaRepository $objetoRepository,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly MontarDetalheObjetoUseCase $montarDetalheObjeto,
        private readonly MontadorModaisCaso $montadorModais,
        private readonly CriarPessoaVinculadaAoObjetoUseCase $criarPessoaVinculada,
        private readonly PessoaRepository $pessoaRepository,
    ) {
    }

    #[Route('/{id}', name: 'cobranca_objeto_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        // O caso âncora (1 por objeto). Objeto ainda sem cobrança (raro após a Fatia 3, que cria o caso
        // na criação) volta para a carteira com um aviso — a página do objeto só existe com uma cobrança.
        $caso = $this->casoRepository->casoAncoraDoObjeto($objeto);
        if ($caso === null) {
            $this->addFlash('warning', 'Este objeto ainda não tem cobrança iniciada.');

            return $this->redirectToRoute('cobranca_carteira_show', ['id' => $objeto->getCarteira()?->getId()]);
        }

        // Modais de mutação só para quem tem a capacidade — mesmo gate que os esconde no Twig. Movimentação
        // financeira é capacidade SEPARADA de gerenciar (SPEC §22). Judicializar exige o módulo `pastas`.
        $usuario = $this->usuarioLogado();
        $podeGerenciar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.gerenciar');
        $podeMovimentar = $this->permissionChecker->hasPermission($usuario, $tenant, 'resources.cobranca.movimentacao_financeira');
        $podeAcessarPastas = $this->permissionChecker->canAccessModule($usuario, $tenant, self::MODULO_PASTAS);

        // B5: se a última mutação falhou na validação, reabrimos aquele modal com o digitado e o erro.
        // One-shot (some na leitura) e por objeto. Consumido sob QUALQUER das duas capacidades porque o
        // erro pode vir tanto de um modal de gerenciar quanto de um financeiro (capacidades separadas).
        $erroModal = ($podeGerenciar || $podeMovimentar) ? $this->consumirErroDeModal($request, $id) : null;

        $forms = $podeGerenciar ? $this->montadorModais->deMutacao($caso, $podeAcessarPastas, $erroModal) : [];
        if ($podeMovimentar) {
            $forms += $this->montadorModais->financeiros($caso, $erroModal);
        }

        $documentos = $this->montadorModais->documentosParaFm($caso);

        return $this->render('cobranca/objeto/show.html.twig', [
            'objeto' => $this->montarDetalheObjeto->executar($objeto, $caso),
            'forms' => $forms,
            'modalErroId' => $erroModal['modalId'] ?? null,
            'casoId' => $caso->getId(),
            'podeGerenciarDocumentos' => $podeGerenciar,
            'secoes' => $documentos['secoes'],
            'arquivosFm' => $documentos['arquivos'],
            // Ações do card da pessoa (Ajuste 10 — era a aba Pessoas) — só para quem gerencia. "Nova
            // pessoa" cadastra+vincula; "Vincular" liga uma pessoa já existente do escritório;
            // "Encerrar vínculo" fecha um vínculo aberto.
            'formNovaPessoa' => $podeGerenciar ? $this->createForm(CriarPessoaVinculadaType::class)->createView() : null,
            'formVincular' => $podeGerenciar
                ? $this->createForm(VincularPessoaAObjetoType::class, null, ['pessoas' => $this->pessoaRepository->opcoesDoTenant($tenant)])->createView()
                : null,
            'formEncerrarVinculo' => $podeGerenciar ? $this->createForm(EncerrarVinculoType::class)->createView() : null,
        ]);
    }

    #[Route('/{id}/pessoas', name: 'cobranca_objeto_pessoa_criar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function criarPessoa(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        // Anti-IDOR: o objeto tem de ser do próprio escritório.
        $objeto = $this->objetoRepository->findOneByIdDoTenant($id, $tenant);
        if ($objeto === null) {
            throw $this->createNotFoundException('Objeto de cobrança não encontrado.');
        }

        $input = new CriarPessoaVinculadaInput();
        $form = $this->createForm(CriarPessoaVinculadaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->criarPessoaVinculada->executar($input, $id, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pessoa cadastrada e vinculada ao objeto.');
            } catch (ObjetoNaoEncontradoException | PessoaNaoEncontradaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $id]);
    }
}
