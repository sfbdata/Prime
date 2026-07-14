<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\RegistrarTentativaCobrancaInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\AlterarPessoaCobradaType;
use App\Cobranca\Form\CancelarAcordoType;
use App\Cobranca\Form\ConcluirAcaoType;
use App\Cobranca\Form\CorrigirPagamentoType;
use App\Cobranca\Form\DefinirProximaAcaoType;
use App\Cobranca\Form\EncerrarCasoType;
use App\Cobranca\Form\JudicializarCasoType;
use App\Cobranca\Form\ReconhecerValorAtualizadoType;
use App\Cobranca\Form\RegistrarLiquidacaoType;
use App\Cobranca\Form\RegistrarObrigacaoType;
use App\Cobranca\Form\RegistrarPagamentoType;
use App\Cobranca\Form\RegistrarTentativaCobrancaType;
use App\Cobranca\Form\RomperAcordoType;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Pasta\Repository\PastaRepository;
use Symfony\Component\Form\FormFactoryInterface;

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
        private readonly PessoaRepository $pessoaRepository,
        private readonly PastaRepository $pastaRepository,
        private readonly CobrancaSecaoRepository $secaoRepository,
        private readonly CobrancaDocumentoRepository $documentoRepository,
    ) {
    }

    /**
     * Views (vazias) dos formulários de mutação renderizados como modais no detalhe. Gated pela
     * capacidade `resources.cobranca.gerenciar` no chamador. Judicializar só entra com o módulo `pastas`.
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    public function deMutacao(CasoCobranca $caso, bool $incluirJudicializar = false): array
    {
        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));

        // O modal de contato abre com data/hora pré-preenchidas com "agora" (editável pelo gestor).
        $contatoAgora = new RegistrarTentativaCobrancaInput();
        $contatoAgora->dataContato = new \DateTimeImmutable();

        $views = [
            'registrarObrigacao' => $this->formFactory->create(RegistrarObrigacaoType::class)->createView(),
            'reconhecerValor' => $this->formFactory->create(ReconhecerValorAtualizadoType::class)->createView(),
            'encerrarCaso' => $this->formFactory->create(EncerrarCasoType::class)->createView(),
            'definirProximaAcao' => $this->formFactory->create(DefinirProximaAcaoType::class)->createView(),
            'concluirAcao' => $this->formFactory->create(ConcluirAcaoType::class)->createView(),
            'registrarTentativa' => $this->formFactory->create(RegistrarTentativaCobrancaType::class, $contatoAgora)->createView(),
            'acordoCriar' => $this->formFactory->create(AcordoCriarType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'romperAcordo' => $this->formFactory->create(RomperAcordoType::class)->createView(),
            'cancelarAcordo' => $this->formFactory->create(CancelarAcordoType::class)->createView(),
            'alterarPessoa' => $this->formFactory->create(AlterarPessoaCobradaType::class, null, [
                'pessoas' => $this->pessoaRepository->opcoesDoTenant($caso->getTenant()),
            ])->createView(),
        ];

        if ($incluirJudicializar) {
            $views['judicializar'] = $this->formFactory->create(JudicializarCasoType::class, null, [
                'pastas' => $this->pastaRepository->opcoesDoTenant($caso->getTenant()),
            ])->createView();
        }

        return $views;
    }

    /**
     * Views dos formulários financeiros (8B-D) da aba Pagamentos & Liquidações. Gated pela capacidade
     * `resources.cobranca.movimentacao_financeira` no chamador.
     *
     * @return array<string, \Symfony\Component\Form\FormView>
     */
    public function financeiros(CasoCobranca $caso): array
    {
        $opcoesObrigacoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));

        return [
            'registrarPagamento' => $this->formFactory->create(RegistrarPagamentoType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'corrigirPagamento' => $this->formFactory->create(CorrigirPagamentoType::class, null, ['obrigacoes' => $opcoesObrigacoes])->createView(),
            'registrarLiquidacao' => $this->formFactory->create(RegistrarLiquidacaoType::class)->createView(),
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
