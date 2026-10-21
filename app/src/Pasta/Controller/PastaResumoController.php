<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Pasta\DTO\PastaExtratoImpressaoOutput;
use App\Pasta\DTO\PastaImpressaoOpcoes;
use App\Pasta\DTO\PastaPendenciasOutput;
use App\Pasta\DTO\PastaPushOutput;
use App\Pasta\DTO\PastaResumoImpressaoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Service\CobrancasDoChecklistDaPasta;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Pasta\Service\PontosDoRelatorioInicial;
use App\Processo\Entity\Processo;
use App\Processo\Service\ResumoDoProcesso;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Shared\Service\GeradorDePdf;
use App\Shared\Service\GeradorDePdfComDompdf;
use App\Twig\NumeroProcessoExtension;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A folha impressa da pasta (desenho 1.2.3: `imprimirResumo`, `impMontar`, `impVals`, `pdfBaixar`).
 *
 * Uma rota de folha, três formas de entrega:
 *  - página própria (`pasta_resumo_imprimir`): abre em aba nova e chama `window.print()` — é o
 *    caminho sem JS do item "Imprimir resumo" do ⋮;
 *  - fragmento (`?fragmento=1`): só a folha, que o `pasta-impressao.js` põe na pré-visualização
 *    da janela "Imprimir" da própria tela da pasta e imprime por iframe oculto;
 *  - PDF (`pasta_resumo_pdf`): a mesma folha gerada no servidor (Dompdf), com o rodapé
 *    "BlueJus · página k de n", baixada como anexo.
 *
 * As três obedecem às MESMAS opções (`PastaImpressaoOpcoes`): seção (resumo, extrato do
 * Financeiro, só a moldura — a prévia do registro e do processo, cujo corpo a tela põe —, ou a
 * observação e o processo montados aqui pelo id), cabeçalho do escritório, dados da pasta,
 * autor, financeiro e tamanho do texto.
 *
 * Observação (`secao=observacao&obs=<id>`) e processo (`secao=processo&processo=<id>`) existem
 * para o "Baixar PDF" dessas folhas (C6-EDT-06): o corpo sai do BANCO pelo id, nunca de HTML
 * vindo da tela. A observação tem de ser de Detalhes desta pasta e deste escritório; o processo,
 * vinculado a esta pasta. Qualquer outra coisa é 404 — depois dos guardas da pasta.
 *
 * Só leitura. O guarda é o MESMO da tela da pasta, em duas camadas explícitas:
 *   1. a pasta é do escritório da sessão — o resolver de entidade busca por PK e o TenantFilter
 *      não se aplica a `find()`, então a conferência de dono não pode ficar implícita (404);
 *   2. o usuário pode VER esta pasta (`resources.pasta.view`, via `canAccessResource`) — 403.
 *
 * O Financeiro (seção resumida, bloco de lançamentos e o extrato inteiro) só sai para quem o vê,
 * pela regra única de `VisibilidadeDoFinanceiroDaPasta` — a mesma da timeline e da análise.
 */
#[Route('/pasta')]
final class PastaResumoController extends AbstractController
{
    /** O mesmo teto que a aba Push usa: as pendências do Push contam sobre esse conjunto. */
    private const PUSH_LIMITE = 100;

    /** Rodapé de cada página do PDF (desenho `pdfBaixar`): o do gerador compartilhado. */
    public const RODAPE_PDF = GeradorDePdf::RODAPE;

    /**
     * A aba de onde vem cada folha avulsa (spec pasta-ocultar-aba §4): com a aba oculta para quem pede,
     * a folha é 403. O resumo geral não está aqui: ele omite as seções das abas ocultas.
     */
    private const ABA_DA_SECAO = [
        PastaImpressaoOpcoes::SECAO_EXTRATO    => AbaDaPasta::Financeiro,
        PastaImpressaoOpcoes::SECAO_OBSERVACAO => AbaDaPasta::Detalhes,
        PastaImpressaoOpcoes::SECAO_PROCESSO   => AbaDaPasta::Processo,
    ];

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PublicacaoDjenRepository $publicacaoDjenRepository,
        private readonly PastaPagamentoRepository $pastaPagamentoRepository,
        private readonly PastaChecklistItemRepository $checklistRepository,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
        private readonly PastaObservacaoDetalhesRepository $observacaoDetalhesRepository,
        private readonly ResumoDoProcesso $resumoDoProcesso,
        private readonly CobrancasDoChecklistDaPasta $cobrancasDoChecklist,
        private readonly GeradorDePdf $geradorDePdf,
        private readonly VisibilidadeDasAbasDaPasta $visibilidadeDasAbas,
    ) {
    }

    #[Route(
        '/{id}/resumo/imprimir',
        name: 'pasta_resumo_imprimir',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function imprimir(Pasta $pasta, Request $request): Response
    {
        $fragmento = $request->query->getBoolean('fragmento');
        $opcoes    = PastaImpressaoOpcoes::daQuery($request->query);
        // A moldura (corpo vazio) só tem sentido dentro da janela da tela.
        // Registro e processo têm prévia montada pela tela; aqui só existem como PDF (C6-EDT-06).
        $valida    = $opcoes->secao !== null
            && ($fragmento || $opcoes->secao !== PastaImpressaoOpcoes::SECAO_MOLDURA)
            && !\in_array($opcoes->secao, [PastaImpressaoOpcoes::SECAO_OBSERVACAO, PastaImpressaoOpcoes::SECAO_PROCESSO], true);

        $contexto = $this->contexto($pasta, $opcoes, $valida, $request);

        $resposta = $this->render('pasta/resumo_impressao.html.twig', $contexto + [
            'modo' => $fragmento ? 'fragmento' : 'pagina',
        ]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }

    #[Route(
        '/{id}/resumo/pdf',
        name: 'pasta_resumo_pdf',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function pdf(Pasta $pasta, Request $request): Response
    {
        $opcoes   = PastaImpressaoOpcoes::daQuery($request->query);
        $contexto = $this->contexto($pasta, $opcoes, $opcoes->secao !== null && $opcoes->secao !== PastaImpressaoOpcoes::SECAO_MOLDURA, $request);

        $html = $this->renderView('pasta/resumo_impressao.html.twig', $contexto + [
            'modo' => 'pdf',
            'logoPdf' => $this->geradorDePdf->logoEmbutido(),
        ]);

        return $this->geradorDePdf->resposta($this->geradorDePdf->gerar($html), (string) $contexto['titulo'], new \DateTimeImmutable());
    }

    /**
     * "BlueJus <título> dd.mm.aaaa.pdf", como o `pdfBaixar` do desenho: barra vira ponto e os
     * caracteres que o sistema de arquivos recusa (e o "·") viram espaço.
     */
    public static function nomeDoArquivo(string $titulo, \DateTimeImmutable $hoje): string
    {
        return GeradorDePdfComDompdf::nomeDoArquivo($titulo, $hoje);
    }

    /**
     * Tudo o que o template precisa, com os guardas na frente. `$valida` falso (seção
     * desconhecida) vira 404 DEPOIS do guarda de dono — a pasta de outro escritório não pode
     * responder diferente conforme a query.
     *
     * @return array<string, mixed>
     */
    private function contexto(Pasta $pasta, PastaImpressaoOpcoes $opcoes, bool $valida, Request $request): array
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->guarda($pasta, $usuario);

        if (!$valida) {
            throw $this->createNotFoundException('Folha de impressão desconhecida.');
        }

        $this->exigirAbaDaSecaoVisivel($pasta, $usuario, $opcoes->secao);
        $veFinanceiro = $this->visibilidadeDoFinanceiro->podeVer($usuario, $tenant, $pasta);
        if ($opcoes->secao === PastaImpressaoOpcoes::SECAO_EXTRATO && !$veFinanceiro) {
            throw $this->createAccessDeniedException('Sem permissão para ver o financeiro desta pasta.');
        }

        // O alvo da observação e do processo, conferido ANTES de montar qualquer coisa.
        $registro     = $opcoes->secao === PastaImpressaoOpcoes::SECAO_OBSERVACAO
            ? $this->observacaoDaPasta($pasta, $tenant, self::idDaQuery($request, 'obs'))
            : null;
        $processoAlvo = $opcoes->secao === PastaImpressaoOpcoes::SECAO_PROCESSO
            ? $this->processoDaPasta($pasta, $tenant, self::idDaQuery($request, 'processo'))
            : null;

        $pagamentos = $this->pastaPagamentoRepository->findByPasta($pasta, $tenant);
        $resumo     = $opcoes->secao === PastaImpressaoOpcoes::SECAO_RESUMO
            ? $this->resumoCompleto($pasta, $tenant, $pagamentos, $veFinanceiro, $usuario)
            // As outras folhas só usam a faixa Pasta/Cliente/Processo: sem Push nem checklist.
            : PastaResumoImpressaoOutput::montar(
                $pasta,
                PastaPendenciasOutput::montar($pasta, 0, $pagamentos, veFinanceiro: $veFinanceiro),
                [],
                $pagamentos,
                incluirFinanceiro: false,
            );

        $titulo = match ($opcoes->secao) {
            PastaImpressaoOpcoes::SECAO_RESUMO  => 'Resumo da pasta ' . $resumo->numero,
            PastaImpressaoOpcoes::SECAO_EXTRATO => 'Extrato de pagamentos',
            // Os mesmos títulos da prévia (pasta-impressao.js e o `data-titulo` do ⋮ do processo).
            PastaImpressaoOpcoes::SECAO_OBSERVACAO => 'Registro do expediente',
            PastaImpressaoOpcoes::SECAO_PROCESSO   => 'Processo ' . (new NumeroProcessoExtension())->numeroCnj($processoAlvo?->getNumeroProcesso()),
            default                             => '',
        };

        return [
            'resumo'       => $resumo,
            'opcoes'       => $opcoes,
            'titulo'       => $titulo,
            // `null` = o bloco não existe na folha (e a opção some da janela).
            'extrato'      => $veFinanceiro ? PastaExtratoImpressaoOutput::montar($pasta, $pagamentos) : null,
            'pastaId'      => $pasta->getId(),
            'impressoEm'   => new \DateTimeImmutable(),
            'impressoPor'  => $usuario->getFullName(),
            // Só na folha da observação: a entidade, para o autor · data · tipo e o texto.
            'registro'       => $registro,
            // Só na folha do processo: as linhas rótulo/valor do "Copiar resumo".
            'linhasProcesso' => $processoAlvo !== null ? $this->resumoDoProcesso->linhas($pasta, $processoAlvo) : [],
        ];
    }

    private function exigirAbaDaSecaoVisivel(Pasta $pasta, User $usuario, ?string $secao): void
    {
        $aba = self::ABA_DA_SECAO[$secao ?? ''] ?? null;
        if ($aba !== null && $this->visibilidadeDasAbas->ocultaPara($pasta, $aba, $usuario)) {
            throw $this->createAccessDeniedException('A aba ' . $aba->rotulo() . ' está oculta para você nesta pasta.');
        }
    }

    /** Id positivo da query, ou `null` (vazio, texto, zero): `getInt` daria 400 em texto. */
    private static function idDaQuery(Request $request, string $chave): ?int
    {
        $valor = (string) $request->query->get($chave, '');

        return ctype_digit($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /**
     * A observação de Detalhes DESTA pasta e DESTE escritório. O Registro e o Financeiro não têm
     * "Imprimir" (EDT-003r) e por isso não entram aqui: a observação financeira nem é procurada.
     */
    private function observacaoDaPasta(Pasta $pasta, Tenant $tenant, ?int $id): PastaObservacaoDetalhes
    {
        $obs = $id !== null
            ? $this->observacaoDetalhesRepository->findOneBy(['id' => $id, 'pasta' => $pasta, 'tenant' => $tenant])
            : null;

        if ($obs === null) {
            throw $this->createNotFoundException('Registro não encontrado nesta pasta.');
        }

        return $obs;
    }

    /** Um processo VINCULADO a esta pasta (e do escritório da sessão), pelo id. */
    private function processoDaPasta(Pasta $pasta, Tenant $tenant, ?int $id): Processo
    {
        if ($id !== null) {
            foreach ($pasta->getPastaProcessos() as $vinculo) {
                $processo = $vinculo->getProcesso();
                if ($processo->getId() === $id && $processo->getTenant() === $tenant) {
                    return $processo;
                }
            }
        }

        throw $this->createNotFoundException('Processo não vinculado a esta pasta.');
    }

    private function guarda(Pasta $pasta, User $usuario): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $pasta->getTenant() !== $tenant) {
            throw $this->createNotFoundException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw $this->createAccessDeniedException('Sem permissão para ver esta pasta.');
        }

        return $tenant;
    }

    /**
     * @param PastaPagamento[] $pagamentos
     */
    private function resumoCompleto(Pasta $pasta, Tenant $tenant, array $pagamentos, bool $veFinanceiro, User $usuario): PastaResumoImpressaoOutput
    {
        // Abas ocultas para quem imprime (spec pasta-ocultar-aba §4): sem as pendências delas e, com o
        // Push oculto, sem "Últimas movimentações". O Financeiro já sai pelo `$veFinanceiro`.
        $ocultas = array_map(static fn (AbaDaPasta $aba): string => $aba->value, $this->visibilidadeDasAbas->abasOcultasPara($pasta, $usuario));
        $numeros = [];
        foreach ($pasta->getPastaProcessos() as $vinculo) {
            $numeros[] = $vinculo->getProcesso()->getNumeroProcesso();
        }
        $push = PastaPushOutput::montar(
            $this->publicacaoDjenRepository->listarItensPorNumerosDoTenant($tenant, $numeros, self::PUSH_LIMITE),
            $numeros,
            self::PUSH_LIMITE,
        );

        // A mesma regra da pasta_show: item do checklist marcado sem arquivo correspondente
        // acende a pendência de Documentos também na folha impressa.
        $conferenciaChecklist = ConferenciaDeAnexosDoChecklist::daPasta(
            $this->checklistRepository->findByPasta($pasta, $tenant),
            $pasta->getDocumentos(),
            $tenant,
        );

        return PastaResumoImpressaoOutput::montar(
            $pasta,
            PastaPendenciasOutput::montar(
                $pasta,
                $push->naoLidas,
                $pagamentos,
                itensMarcadosSemAnexo: $conferenciaChecklist->totalMarcadosSemAnexo(),
                // A pendência do Financeiro ("… e N pagamentos vencidos") é dado financeiro.
                veFinanceiro: $veFinanceiro,
                documentosCobrados: $this->cobrancasDoChecklist->cobradosPendentes($pasta),
                relatorioInicial: $this->pontosDoRelatorio($pasta, $tenant),
            )->semAbas($ocultas),
            \in_array(AbaDaPasta::Push->value, $ocultas, true) ? [] : $push->itens,
            $pagamentos,
            incluirFinanceiro: $veFinanceiro,
        )->semSecoesDasAbas($ocultas);
    }

    /**
     * `imprimirResumo()` do desenho (dc L.4832) lista as pendências de todas as abas, Detalhes inclusive:
     * o relatório inteiro, sem o teto da lista de exibição.
     */
    private function pontosDoRelatorio(Pasta $pasta, Tenant $tenant): PontosDoRelatorioInicial
    {
        return PontosDoRelatorioInicial::dosTextos($this->observacaoDetalhesRepository->conteudosDaPasta($pasta, $tenant));
    }
}
