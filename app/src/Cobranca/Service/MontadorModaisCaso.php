<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\AlterarPessoaCobradaType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\ConcluirAcaoType;
use App\Cobranca\Form\CorrigirPagamentoType;
use App\Cobranca\Form\DefinirProximaAcaoType;
use App\Cobranca\Form\EditarObrigacaoType;
use App\Cobranca\Form\EncerrarCasoType;
use App\Cobranca\Form\JudicializarCasoType;
use App\Cobranca\Form\RegistrarLiquidacaoType;
use App\Cobranca\Form\RegistrarObrigacaoType;
use App\Cobranca\Form\RegistrarPagamentoType;
use App\Cobranca\Form\EditarAnotacaoType;
use App\Cobranca\Form\RegistrarAnotacaoType;
use App\Cobranca\Form\RegistrarTentativaCobrancaType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Pasta\Repository\PastaRepository;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;

/**
 * Monta as views dos modais de mutação e o mapa de documentos para o file-manager de um Caso — o
 * "corpo operacional" que a página do detalhe renderiza (Onda 8B/8C). Extraído do `CasoController` no
 * ajuste 2 para ser reusado pela página unificada do Objeto (`ObjetoController`) sem duplicar a
 * construção dos formulários. Só monta views (leitura); o processamento POST segue em cada controller
 * de recurso.
 */
final class MontadorModaisCaso
{
    public function __construct(
        private readonly FormFactoryInterface $formFactory,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly PessoaRepository $pessoaRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly CobrancaSecaoRepository $secaoRepository,
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly EncargosVivos $encargosVivos,
        private readonly ResolvedorConfigEncargos $resolvedorConfig,
        private readonly ComporNomeDaPastaJudicial $comporNomeDaPastaJudicial,
    ) {
    }

    /**
     * Views (vazias) dos formulários de mutação renderizados como modais no detalhe. Gated pela
     * capacidade `resources.cobranca.gerenciar` no chamador. Judicializar só entra com o módulo `pastas`.
     *
     * @param array{form: string, modalId: string, payload: array<string, mixed>, acao: ?string}|null $erroModal B5:
     *        estado one-shot da última mutação que falhou na validação; reidrata aquele form (valores +
     *        erros) em vez de abri-lo vazio. Null = fluxo normal (todos os modais vazios).
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    public function deMutacao(CasoCobranca $caso, bool $incluirJudicializar = false, ?array $erroModal = null): array
    {
        // INV-I (ajuste 9): o form de acordo só oferece DÍVIDA ORIGINAL. Parcela de acordo vigente fica de
        // fora — acordo sobre acordo duplicaria a dívida no saldo ao romper o acordo de origem. NÃO é a
        // lista do saldo nem a do pagamento (essa, em `financeiros()`, segue usando `doCasoExigiveis`:
        // pagar parcela de acordo vigente é o fluxo normal).
        $substituiveis = $this->obrigacaoRepository->doCasoSubstituiveis($caso);

        // Encargos AO VIVO (spec §6.2): o gerador de acordo oferece o REMANESCENTE sobre o exigível
        // vivo — as mesmas obrigações da linha da dívida. Hidrata para HOJE antes de montar as opções,
        // para o valor do modal bater com o `restante` exibido (INV-V5). Config resolvida 1× por caso.
        $this->encargosVivos->hidratar($this->resolvedorConfig->resolverDoCaso($caso), $substituiveis);

        // Ajuste 10 (spec §5.3): o gerador precisa do REMANESCENTE, não do valor cheio — senão sugere
        // renegociar o que já foi pago. Uma query em lote, mesmo mapa do detalhe do caso.
        $alocadoPorObrigacao = $this->alocacaoRepository->somasPorObrigacaoDosCasos(
            [$caso->getId()],
            $caso->getTenant(),
        );

        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($substituiveis, $alocadoPorObrigacao);
        $valoresObrigacoes = AcordoCriarType::valoresObrigacoes($substituiveis, $alocadoPorObrigacao);

        // O modal de contato abre com data/hora pré-preenchidas com "agora" (editável pelo gestor).
        $contatoAgora = new RegistrarTentativaCobrancaInput();
        $contatoAgora->dataContato = new \DateTimeImmutable();

        // Melhoria UX: o acordo se lavra "hoje" no caso comum — o modal abre com a data do acordo já em
        // hoje (editável). Espelha o contato/pagamento. O "1º vencimento" do gerador (input só-JS) nasce
        // em hoje+1mês no cliente, derivado desta data.
        $acordoHoje = new CriarAcordoInput();
        $acordoHoje->dataAcordo = new \DateTimeImmutable('today');

        // #9-T3: o editor de honorários do CASO saiu da tela (o meio da cascata é o OBJETO desde T1 —
        // `ObjetoController::configEncargosObjetoView` monta o novo modal). O backend deste form
        // (`EditarConfiguracaoCasoType`/`EditarConfiguracaoCasoUseCase`/rota `cobranca_caso_editar_config`)
        // fica DORMENTE de propósito (spec §9, reversível) — só parou de ser MONTADO aqui.

        $views = [
            'registrarObrigacao' => $this->reidratarSeErro($this->formFactory->create(RegistrarObrigacaoType::class), 'registrarObrigacao', $erroModal),
            'editarObrigacao' => $this->reidratarSeErro($this->formFactory->create(EditarObrigacaoType::class), 'editarObrigacao', $erroModal),
            'encerrarCaso' => $this->formFactory->create(EncerrarCasoType::class)->createView(),
            'definirProximaAcao' => $this->reidratarSeErro($this->formFactory->create(DefinirProximaAcaoType::class), 'definirProximaAcao', $erroModal),
            'concluirAcao' => $this->reidratarSeErro($this->formFactory->create(ConcluirAcaoType::class), 'concluirAcao', $erroModal),
            'registrarTentativa' => $this->reidratarSeErro($this->formFactory->create(RegistrarTentativaCobrancaType::class, $contatoAgora), 'registrarTentativa', $erroModal),
            // Anotação livre, hoje no topo da aba Cobrança (SPEC UX §7). É campo INLINE, não modal — mas
            // entra na reidratação assim mesmo: o `modalId` guardado vem vazio, então nada reabre, e o
            // ganho é só o que a SPEC §7.3 exige — o texto digitado sobrevive ao erro de validação.
            'registrarAnotacao' => $this->reidratarSeErro($this->formFactory->create(RegistrarAnotacaoType::class), 'registrarAnotacao', $erroModal),
            // Modal COMPARTILHADO de correção (2026-07-22): um só para todas as anotações — o JS injeta
            // a action da linha clicada e o texto atual. Um modal por linha inflaria o HTML à toa.
            'editarAnotacao' => $this->formFactory->create(EditarAnotacaoType::class)->createView(),
            'acordoCriar' => $this->formFactory->create(AcordoCriarType::class, $acordoHoje, [
                'obrigacoes' => $opcoesObrigacoes,
                'valores' => $valoresObrigacoes,
                'alocados' => $alocadoPorObrigacao,
            ])->createView(),
            'romperAcordo' => $this->reidratarSeErro($this->formFactory->create(RomperAcordoType::class), 'romperAcordo', $erroModal),
            'cancelarAcordo' => $this->formFactory->create(CancelarAcordoType::class)->createView(),
            'alterarPessoa' => $this->reidratarSeErro($this->formFactory->create(AlterarPessoaCobradaType::class, null, [
                'pessoas' => $this->pessoaRepository->opcoesDoTenant($caso->getTenant()),
            ]), 'alterarPessoa', $erroModal),
        ];

        if ($incluirJudicializar) {
            // O modal abre PREENCHIDO (spec `cobranca-judicializar-cria-pasta.md` §1): o cliente da
            // pasta nova sai no padrão `<fantasia do credor da carteira> - <pessoa cobrada>`, e a ação
            // é `AÇÃO MONITÓRIA` — a de todos os casos de cobrança. Os dois seguem editáveis; o gestor
            // vê antes de criar.
            $judicializar = new JudicializarCasoInput();
            $judicializar->nomeCliente = $this->comporNomeDaPastaJudicial->paraCaso($caso);
            $judicializar->nomeAcao = JudicializarCasoInput::ACAO_PADRAO;

            $views['judicializar'] = $this->reidratarSeErro($this->formFactory->create(JudicializarCasoType::class, $judicializar, [
                'pastas' => $this->pastaRepository->opcoesDoTenant($caso->getTenant()),
            ]), 'judicializar', $erroModal);
        }

        return $views;
    }

    /**
     * B5: se o estado de erro (one-shot) aponta para ESTE form, re-submete o payload cru — o FormView
     * resultante já carrega os valores digitados e os erros de validação, e o `form_row` os renquadra no
     * campo. Sem correspondência, devolve a view vazia normal. O Form entra já configurado (com as opções
     * dele), então o re-submit respeita choices/valores próprios de cada modal.
     *
     * @param array{form: string, modalId: string, payload: array<string, mixed>, acao: ?string}|null $erroModal
     */
    private function reidratarSeErro(FormInterface $form, string $formKey, ?array $erroModal): FormView
    {
        if ($erroModal !== null && $erroModal['form'] === $formKey) {
            $form->submit($erroModal['payload']);
        }

        return $form->createView();
    }

    /**
     * Views dos formulários financeiros (8B-D) da aba Pagamentos & Liquidações. Gated pela capacidade
     * `resources.cobranca.movimentacao_financeira` no chamador.
     *
     * @param array{form: string, modalId: string, payload: array<string, mixed>, acao: ?string}|null $erroModal B5:
     *        reidrata o form financeiro cuja validação falhou (registrar pagamento/liquidação).
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    public function financeiros(CasoCobranca $caso, ?array $erroModal = null): array
    {
        // Encargos AO VIVO: o modal de pagamento lista as obrigações exigíveis pelo valor vivo (INV-V5).
        $exigiveis = $this->obrigacaoRepository->doCasoExigiveis($caso);
        $this->encargosVivos->hidratar($this->resolvedorConfig->resolverDoCaso($caso), $exigiveis);
        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($exigiveis);

        // Ajuste 10 (B1): o pagamento abre com a data de HOJE — o caso comum é registrar o que entrou no
        // dia, e o gestor só corrige a exceção. Espelha o modal de contato (`deMutacao`, acima). Só o
        // REGISTRO nasce preenchido: `corrigirPagamento` mexe num pagamento que já tem data própria, e
        // sugerir "hoje" ali convidaria a sobrescrever a data real do dinheiro.
        $pagamentoHoje = new RegistrarPagamentoInput();
        $pagamentoHoje->data = new \DateTimeImmutable('today');

        return [
            'registrarPagamento' => $this->reidratarSeErro($this->formFactory->create(RegistrarPagamentoType::class, $pagamentoHoje, ['obrigacoes' => $opcoesObrigacoes]), 'registrarPagamento', $erroModal),
            'corrigirPagamento' => $this->reidratarSeErro($this->formFactory->create(CorrigirPagamentoType::class, null, ['obrigacoes' => $opcoesObrigacoes]), 'corrigirPagamento', $erroModal),
            'registrarLiquidacao' => $this->reidratarSeErro($this->formFactory->create(RegistrarLiquidacaoType::class), 'registrarLiquidacao', $erroModal),
        ];
    }

    /**
     * Documentos e seções do caso mapeados em arrays simples para o file-manager (Onda 8C) — sem expor
     * entidades Doctrine ao Twig. Leitura tenant-scoped (os repos filtram por caso+tenant). A contagem
     * por seção é derivada dos próprios documentos (sem N+1). O documento "sem seção" fica em `geral`.
     *
     * @return array{secoes: list<array{id: int, nome: string, total: int}>, arquivos: list<array{doc: array<string, mixed>, secao: string}>}
     */
    public function documentosParaFm(CasoCobranca $caso): array
    {
        $contagem = [];
        $arquivos = [];
        foreach ($this->documentoRepository->documentosDoCaso($caso) as $doc) {
            $secaoId = $doc->getSecao()?->getId();
            $chave = $secaoId !== null ? (string) $secaoId : 'geral';
            $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            $arquivos[] = [
                'doc' => [
                    'id' => (int) $doc->getId(),
                    'nomeOriginal' => $doc->getNomeOriginal(),
                    'ordem' => $doc->getOrdem(),
                    'tamanhoBytes' => $doc->getTamanhoBytes(),
                    'carregadoEm' => $doc->getCarregadoEm(),
                    'mimeType' => $doc->getMimeType(),
                    'categoriaLabel' => $doc->getCategoria()->rotulo(),
                    'descricao' => $doc->getDescricao(),
                ],
                'secao' => $chave,
            ];
        }

        $secoes = [];
        foreach ($this->secaoRepository->secoesDoCaso($caso) as $secao) {
            $secoes[] = [
                'id' => (int) $secao->getId(),
                'nome' => $secao->getNome(),
                'total' => $contagem[(string) $secao->getId()] ?? 0,
            ];
        }

        return ['secoes' => $secoes, 'arquivos' => $arquivos];
    }
}
