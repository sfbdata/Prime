<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\AcordoDetalheOutput;
use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\EditarAcordoInput;
use App\Cobranca\DTO\MarcarAcordoCumpridoInput;
use App\Cobranca\DTO\ParcelaEdicaoInput;
use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\CategoriaDocumentoAcordo;
use App\Cobranca\Enum\Periodicidade;
use App\Cobranca\Exception\AcordoComParcelasRenegociadasException;
use App\Cobranca\Exception\AcordoNaoAtivoException;
use App\Cobranca\Exception\ArquivoMuitoGrandeException;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoComPagamentoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ObrigacaoNaoEhDividaOriginalException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Exception\TipoArquivoNaoPermitidoException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\EditarAcordoType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\GeradorParcelamento;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Cobranca\UseCase\EditarAcordoUseCase;
use App\Cobranca\UseCase\EnviarDocumentoAcordoUseCase;
use App\Cobranca\UseCase\ExcluirDocumentoAcordoUseCase;
use App\Cobranca\UseCase\MarcarAcordoCumpridoUseCase;
use App\Cobranca\UseCase\MontarDetalheAcordoUseCase;
use App\Cobranca\UseCase\RomperAcordoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly AcordoDocumentoRepository $acordoDocumentoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CriarAcordoUseCase $criarAcordo,
        private readonly RomperAcordoUseCase $romperAcordo,
        private readonly CancelarAcordoUseCase $cancelarAcordo,
        private readonly MarcarAcordoCumpridoUseCase $marcarCumprido,
        private readonly GeradorParcelamento $geradorParcelamento,
        private readonly MontarDetalheAcordoUseCase $montarDetalheAcordo,
        private readonly EditarAcordoUseCase $editarAcordo,
        private readonly EnviarDocumentoAcordoUseCase $enviarDocumentoAcordo,
        private readonly ExcluirDocumentoAcordoUseCase $excluirDocumentoAcordo,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
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

        $detalhe = $this->montarDetalheAcordo->executar($acordo, $tenant);

        // Barra de ações (Fatia 4) só para quem gerencia, em acordo ATIVO e caso aberto — mesmo gate
        // que o Twig usa para escondê-la. Forms com `action` fixa (há UM acordo nesta página): não é o
        // padrão de modal reutilizável, então não há `action` vazia para o guard de submit cobrir.
        $podeAgir = $detalhe->ativo
            && !$detalhe->casoEncerrado
            && $this->permissionChecker->hasPermission($this->usuarioLogado(), $tenant, 'resources.cobranca.gerenciar');

        $forms = [];
        $parcelasTravadas = [];
        if ($podeAgir) {
            $forms = [
                'editarAcordo' => $this->createForm(EditarAcordoType::class, $this->inputDeEdicao($acordo, $detalhe))->createView(),
                'romperAcordo' => $this->createForm(RomperAcordoType::class)->createView(),
                'cancelarAcordo' => $this->createForm(CancelarAcordoType::class)->createView(),
            ];
            // Mapa id → motivo da trava. O editor as mostra travadas; o UseCase é quem de fato recusa
            // (INV-C pagamento; invariável 14 para a parcela já substituída por outro acordo vigente).
            foreach ($acordo->getParcelas() as $parcela) {
                if ($parcela->getAcordoSubstituto()?->getStatus()->ehVigente() ?? false) {
                    $parcelasTravadas[(int) $parcela->getId()] = ['tipo' => 'acordo', 'alocado' => 0];
                }
            }
            foreach ($detalhe->parcelas as $parcela) {
                if ($parcela->alocado > 0 && !isset($parcelasTravadas[$parcela->id])) {
                    $parcelasTravadas[$parcela->id] = ['tipo' => 'pagamento', 'alocado' => $parcela->alocado];
                }
            }
        }

        return $this->render('cobranca/acordo/show.html.twig', [
            'acordo' => $detalhe,
            'forms' => $forms,
            'podeAgir' => $podeAgir,
            'parcelasTravadas' => $parcelasTravadas,
            // Documentos do acordo (Ajuste #4): aba "Documentos" — lista cronológica.
            'documentos' => $this->acordoDocumentoRepository->listarPorAcordo($acordo),
            'categoriasAcordo' => CategoriaDocumentoAcordo::cases(),
        ]);
    }

    /**
     * Edita o acordo (Ajuste 7, Fatia 4): reajusta as parcelas não pagas. Capacidade `gerenciar`,
     * resolução tenant-safe → 404 ANTES do CSRF, Form → UseCase, PRG de volta ao detalhe.
     */
    #[Route('/acordos/{id}/editar', name: 'cobranca_acordo_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acordo = $this->acordoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acordo === null) {
            throw $this->createNotFoundException('Acordo não encontrado.');
        }

        $input = new EditarAcordoInput();
        $input->acordoId = $id;
        $form = $this->createForm(EditarAcordoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarAcordo->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Acordo atualizado.');
            } catch (AcordoNaoAtivoException | CasoEncerradoException | ObrigacaoComPagamentoException | ObrigacaoDeAcordoException | ObrigacaoNaoEncontradaException | ParcelamentoInvalidoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_acordo_show', ['id' => $id]);
    }

    /**
     * Pré-preenche o editor com o conjunto ATUAL de parcelas (a entrada é uma linha como as outras —
     * D8). O editor trabalha no `valorOriginal` da parcela (encargos são outra história: obrigação de
     * acordo vigente nem aceita "editar obrigação"), por isso o total pré-preenchido é a soma deles.
     */
    private function inputDeEdicao(Acordo $acordo, AcordoDetalheOutput $detalhe): EditarAcordoInput
    {
        $input = new EditarAcordoInput();
        $input->acordoId = $detalhe->id;
        $soma = 0;

        foreach ($acordo->getParcelas() as $parcela) {
            $linha = new ParcelaEdicaoInput();
            $linha->obrigacaoId = $parcela->getId();
            $linha->descricao = $parcela->getDescricao();
            $linha->valor = $parcela->getValorOriginal();
            $linha->vencimento = $parcela->getVencimentoOriginal();
            $input->parcelas[] = $linha;
            $soma += $parcela->getValorOriginal();
        }

        $input->valorTotalNegociado = $soma;

        return $input;
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
        // ASSIMETRIA PROPOSITAL (ajuste 9) — não "corrija" para `doCasoSubstituiveis`:
        // o RENDER (`MontadorModaisCaso::deMutacao`) oferece só as substituíveis, mas o POST valida contra
        // o conjunto MAIOR (exigíveis). Assim tudo que a tela ofereceu continua válido (substituíveis ⊂
        // exigíveis), e uma parcela submetida por fora chega ao guard do UseCase, que a recusa com uma
        // mensagem de domínio ("é parcela de um acordo... rompa o acordo atual primeiro") em vez do
        // "valor inválido" cru do ChoiceType. É o que torna INV-L verificável: com as duas listas iguais o
        // guard fica inalcançável e o catch abaixo vira código morto — não-testável e livre para apodrecer.
        $opcoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));
        $form = $this->createForm(AcordoCriarType::class, $input, ['obrigacoes' => $opcoes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->criarAcordo->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Acordo criado.');
            } catch (CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | ObrigacaoJaSubstituidaException | ObrigacaoNaoEhDividaOriginalException | ParcelamentoInvalidoException $e) {
                // `ObrigacaoNaoEhDividaOriginalException` = INV-I (acordo sobre acordo) e é ALCANÇÁVEL:
                // as choices do POST são as exigíveis (ver comentário acima), então uma parcela submetida
                // chega ao guard. Sem este catch, 500 — provado por mutação no
                // AcordoSobreAcordoBloqueadoControllerTest, que exige a mensagem do guard no flash.
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        // Ajuste 10 (B4): o acordo REESCREVE a dívida (substitui as originais por parcelas) — é na
        // "Dívida em aberto" que o gestor confere o resultado. `redirectToRoute` não aceita fragmento.
        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]) . '#secao-divida');
    }

    #[Route('/acordos/{id}/romper', name: 'cobranca_acordo_romper', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function romper(int $id, Request $request): Response
    {
        $input = new RomperAcordoInput();
        $input->acordoId = $id;

        return $this->mutarAcordoComMotivo($id, $request, $input, RomperAcordoType::class, fn ($i, $t, $u) => $this->romperAcordo->executar($i, $t, $u), 'Acordo rompido.', 'romperAcordo', 'modalRomperAcordo', 'romper_acordo', 'cobranca_acordo_romper');
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

            return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $objetoId]) . '#secao-divida');
        }

        $input = new MarcarAcordoCumpridoInput();
        $input->acordoId = $id;
        try {
            $this->marcarCumprido->executar($input, $tenant, $this->usuarioLogado());
            $this->addFlash('success', 'Acordo marcado como cumprido.');
        } catch (AcordoNaoAtivoException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $objetoId]) . '#secao-divida');
    }

    // ------------------------------------------------------- documentos (#4) ---

    /**
     * Envia um documento do acordo (Ajuste #4): form multipart HTML puro + PRG — mesma mecânica
     * simplificada da carteira (não é o file-manager JSON do Caso). Whitelist de MIME/tamanho e o
     * guard de tenant vivem no `EnviarDocumentoAcordoUseCase`.
     */
    #[Route('/acordos/{id}/documentos', name: 'cobranca_acordo_documento_upload', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function uploadDocumento(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acordo = $this->acordoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acordo === null) {
            throw $this->createNotFoundException('Acordo não encontrado.');
        }

        if (!$this->isCsrfTokenValid('cobranca_acordo_documento_upload_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_acordo_show', ['id' => $id]);
        }

        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile) {
            $this->addFlash('danger', 'Nenhum arquivo enviado.');

            return $this->redirectToRoute('cobranca_acordo_show', ['id' => $id]);
        }

        $categoria = CategoriaDocumentoAcordo::tryFrom((string) $request->request->get('categoria', ''))
            ?? CategoriaDocumentoAcordo::Outro;
        $observacao = trim((string) $request->request->get('observacao', ''));

        try {
            $this->enviarDocumentoAcordo->executar($acordo, $arquivo, $categoria, $observacao !== '' ? $observacao : null, $tenant);
            $this->addFlash('success', 'Documento adicionado.');
        } catch (TipoArquivoNaoPermitidoException | ArquivoMuitoGrandeException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('cobranca_acordo_show', ['id' => $id]);
    }

    #[Route('/acordos/documentos/{docId}/excluir', name: 'cobranca_acordo_documento_excluir', methods: ['POST'], requirements: ['docId' => '\d+'])]
    public function excluirDocumento(int $docId, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $documento = $this->acordoDocumentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }
        $acordoId = (int) $documento->getAcordo()?->getId();

        if (!$this->isCsrfTokenValid('cobranca_acordo_documento_excluir_' . $docId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de segurança inválido.');

            return $this->redirectToRoute('cobranca_acordo_show', ['id' => $acordoId]);
        }

        $this->excluirDocumentoAcordo->executar($documento, $tenant);
        $this->addFlash('success', 'Documento removido.');

        return $this->redirectToRoute('cobranca_acordo_show', ['id' => $acordoId]);
    }

    #[Route('/acordos/documentos/{docId}/download', name: 'cobranca_acordo_documento_download', methods: ['GET'], requirements: ['docId' => '\d+'])]
    public function downloadDocumento(int $docId): BinaryFileResponse
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo de Cobranças.');
        }

        $documento = $this->acordoDocumentoRepository->findOneByIdDoTenant($docId, $tenant);
        if ($documento === null) {
            throw $this->createNotFoundException('Documento não encontrado.');
        }

        $caminho = $this->storage->caminho($this->cobrancasUploadsDir . '/' . $tenant->getId(), $documento->getCaminhoArquivo());
        if (!$this->storage->existe($caminho)) {
            throw $this->createNotFoundException('Arquivo não encontrado no armazenamento.');
        }

        return $this->storage->servir($caminho, $documento->getNomeOriginal(), inline: false);
    }

    /**
     * Fluxo comum de romper/cancelar (per-acordo, com motivo): gate + resolução tenant-safe + Form +
     * PRG. `$input` (com acordoId setado) alimenta o Form; `$executar($input, $tenant, $user)` chama
     * o UseCase específico.
     */
    private function mutarAcordoComMotivo(int $id, Request $request, object $input, string $tipoForm, callable $executar, string $sucesso, ?string $formKey = null, ?string $modalId = null, ?string $blockPrefix = null, ?string $acaoRota = null): Response
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
            } catch (AcordoNaoAtivoException | AcordoComParcelasRenegociadasException $e) {
                // `AcordoComParcelasRenegociadasException` = ajuste 9: romper/cancelar acordo cujas parcelas
                // outro acordo vigente renegociou duplicaria a dívida no saldo. Sem este catch, 500.
                $this->addFlash('danger', $e->getMessage());
            }
        } elseif ($formKey !== null) {
            // B5: modal REUTILIZÁVEL — guarda o payload e a URL da ação (por acordo). Só o ROMPER passa por
            // aqui: seu `motivo` é obrigatório (NotBlank), então o gestor pode errar por digitação.
            $this->tratarFormInvalido($request, $form, $objetoId, $formKey, $modalId, $blockPrefix, $this->generateUrl($acaoRota, ['id' => $id]));
        } else {
            // Cancelar acordo cai aqui (sem B5) por baixo valor, não por ser inalcançável: seu `motivo` é
            // OPCIONAL e só tem `Length(max: 255)`, com `maxlength=255` no cliente. A única falha de
            // validação de campo viria de um POST forjado com >255 chars, que só perderia o próprio excesso.
            $this->flashErrosDoForm($form);
        }

        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $objetoId]) . '#secao-divida');
    }
}
