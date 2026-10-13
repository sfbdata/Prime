<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
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
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use App\Processo\Entity\Processo;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Twig\NumeroProcessoExtension;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\HeaderUtils;
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

    /** Rodapé de cada página do PDF (desenho `pdfBaixar`: 8 pt, cinza #7b93a2, centralizado). */
    public const RODAPE_PDF = 'BlueJus · página {PAGE_NUM} de {PAGE_COUNT}';

    private const LOGO = '/public/images/logo/bluejus_simbolo.svg';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PublicacaoDjenRepository $publicacaoDjenRepository,
        private readonly PastaPagamentoRepository $pastaPagamentoRepository,
        private readonly PastaChecklistItemRepository $checklistRepository,
        private readonly VisibilidadeDoFinanceiroDaPasta $visibilidadeDoFinanceiro,
        private readonly PastaObservacaoDetalhesRepository $observacaoDetalhesRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
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
        $valida    = $opcoes->secao !== null && ($fragmento || $opcoes->secao !== PastaImpressaoOpcoes::SECAO_MOLDURA);

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
            'logoPdf' => $this->logoEmbutido(),
        ]);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        // A folha é toda do servidor: nada aqui deve buscar recurso externo (o logo vai embutido).
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $canvas  = $dompdf->getCanvas();
        $metrica = $dompdf->getFontMetrics();
        $fonte   = $metrica->getFont('Helvetica');
        $largura = $metrica->getTextWidth('BlueJus · página 10 de 10', $fonte, 8);
        $canvas->page_text(
            ($canvas->get_width() - $largura) / 2,
            $canvas->get_height() - 22,
            self::RODAPE_PDF,
            $fonte,
            8,
            [123 / 255, 147 / 255, 162 / 255],
        );

        $nome = self::nomeDoArquivo((string) $contexto['titulo'], new \DateTimeImmutable());

        return new Response((string) $dompdf->output(), Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'X-Robots-Tag'        => 'noindex',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $nome,
                self::nomeAscii($nome),
            ),
        ]);
    }

    /**
     * "BlueJus <título> dd.mm.aaaa.pdf", como o `pdfBaixar` do desenho: barra vira ponto e os
     * caracteres que o sistema de arquivos recusa (e o "·") viram espaço.
     */
    public static function nomeDoArquivo(string $titulo, \DateTimeImmutable $hoje): string
    {
        $nome = 'BlueJus ' . str_replace('/', '.', $titulo) . ' ' . $hoje->format('d.m.Y');
        $nome = (string) preg_replace('/[\\\\\/:*?"<>|·]+/u', ' ', $nome);
        $nome = trim((string) preg_replace('/\s+/u', ' ', $nome));

        return $nome . '.pdf';
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
            ? $this->resumoCompleto($pasta, $tenant, $pagamentos, $veFinanceiro)
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
            'linhasProcesso' => $processoAlvo !== null ? self::linhasDoProcesso($pasta, $processoAlvo) : [],
        ];
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

    /**
     * As linhas do "Copiar resumo do processo" (`_processos_vinculados.html.twig`, `resumo`), na
     * mesma ordem e com a mesma regra — linha sem valor não entra —, já partidas em rótulo e valor
     * como a tabela que o pasta-processo.js monta para a prévia. A primeira e a última não têm
     * valor: "Processo N" e "Pasta N · identificador".
     *
     * @return list<array{rotulo: string, valor: string}>
     */
    public static function linhasDoProcesso(Pasta $pasta, Processo $processo): array
    {
        $linhas = [['rotulo' => 'Processo ' . (new NumeroProcessoExtension())->numeroCnj($processo->getNumeroProcesso()), 'valor' => '']];
        $valorCausa = $pasta->getValorCausa();

        $campos = [
            'Ação'           => $pasta->getNomeAcao(),
            'Classe'         => $processo->getClasseProcessual(),
            'Tribunal'       => $processo->getSiglaTribunal(),
            'Situação'       => $processo->getSituacaoProcesso(),
            'Órgão julgador' => $processo->getOrgaoJulgador(),
            'Distribuição'   => $processo->getDataDistribuicao()?->format('d/m/Y'),
            // Zero é dado (como na tela); só o nulo some.
            'Valor da causa' => $valorCausa !== null ? 'R$ ' . number_format((float) $valorCausa, 2, ',', '.') : null,
        ];
        foreach ($campos as $rotulo => $valor) {
            if ($valor !== null && $valor !== '' && $valor !== '0') {
                $linhas[] = ['rotulo' => $rotulo, 'valor' => (string) $valor];
            }
        }

        $nomeCliente = $pasta->getNomeCliente();
        $linhas[]    = [
            'rotulo' => 'Pasta ' . ($pasta->getNup() ?? ('#' . $pasta->getId())) . ($nomeCliente !== null && $nomeCliente !== '' && $nomeCliente !== '0' ? ' · ' . $nomeCliente : ''),
            'valor'  => '',
        ];

        return $linhas;
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
    private function resumoCompleto(Pasta $pasta, Tenant $tenant, array $pagamentos, bool $veFinanceiro): PastaResumoImpressaoOutput
    {
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
            ),
            $push->itens,
            $pagamentos,
            incluirFinanceiro: $veFinanceiro,
        );
    }

    /** O símbolo do BlueJus como `data:` — o Dompdf roda sem acesso a arquivo nem rede. */
    private function logoEmbutido(): ?string
    {
        $svg = @file_get_contents($this->projectDir . self::LOGO);

        return $svg !== false ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
    }

    private static function nomeAscii(string $nome): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);

        return (string) preg_replace('/[^\x20-\x7E]|["%\\\\\/]/', '_', $ascii !== false ? $ascii : $nome);
    }
}
