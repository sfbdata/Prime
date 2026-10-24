<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Armazenamento\ChavesDeTrabalhoExterno;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DetalheDoCurriculo;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PreviaDePerfil;
use App\TrabalhoExterno\UseCase\AnexarArquivoAoCurriculoUseCase;
use App\TrabalhoExterno\UseCase\AnotarCurriculoUseCase;
use App\TrabalhoExterno\UseCase\ExcluirCurriculoUseCase;
use App\TrabalhoExterno\UseCase\ExcluirMeuCurriculoUseCase;
use App\TrabalhoExterno\UseCase\ExportarAlertasCsvUseCase;
use App\TrabalhoExterno\UseCase\ExportarCurriculosCsvUseCase;
use App\TrabalhoExterno\UseCase\GerarDocumentoDoCurriculoUseCase;
use App\TrabalhoExterno\UseCase\GerarWordDoMeuCurriculoUseCase;
use App\TrabalhoExterno\UseCase\ListarCurriculosUseCase;
use App\TrabalhoExterno\UseCase\MarcarCurriculosEmLoteUseCase;
use App\TrabalhoExterno\UseCase\MontarPainelDeCurriculosUseCase;
use App\TrabalhoExterno\UseCase\MudarStatusDoCurriculoUseCase;
use App\TrabalhoExterno\UseCase\PreVisualizarCurriculoUseCase;
use App\TrabalhoExterno\UseCase\RemoverArquivoDoCurriculoUseCase;
use App\TrabalhoExterno\UseCase\SalvarCurriculoECandidatarUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Currículos e banco de talentos (spec VS 18.10.26 §4.5). A API é JSON, como as vagas: escritas com
 * CSRF no header `X-CSRF-Token`, leituras aceitam o `?como=` da prévia do Master, escritas nunca.
 * Arquivos, documento, PDF e CSV saem por aqui, depois da política, como download (a foto, inline).
 */
#[Route('/trabalho-externo')]
final class CurriculosController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    private const CODIGO = 'CV-\d{1,9}';
    private const TIPO = 'foto|oab|matricula|curriculo';
    private const FILTROS_DO_CSV = ['filtro', 'busca', 'area', 'situacao', 'uf', 'termo'];

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly PreviaDePerfil $previa,
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly DetalheDoCurriculo $detalhe,
    ) {
    }

    #[Route('/api/curriculos', name: 'trabalho_externo_curriculos', methods: ['GET'])]
    public function listar(Request $request, ListarCurriculosUseCase $listar): JsonResponse
    {
        return $this->ler($request, static fn (Contexto $ctx): array => $listar->executar($ctx));
    }

    /** O Painel do banco de talentos (spec §4.9): só o Master; a Equipe, o externo e a prévia levam 403. */
    #[Route('/api/curriculos/painel', name: 'trabalho_externo_curriculos_painel', methods: ['GET'])]
    public function painel(Request $request, MontarPainelDeCurriculosUseCase $montar): JsonResponse
    {
        return $this->ler($request, static fn (Contexto $ctx): array => $montar->executar($ctx));
    }

    #[Route('/api/curriculos/{codigo}', name: 'trabalho_externo_curriculo', methods: ['GET'], requirements: ['codigo' => self::CODIGO])]
    public function detalhe(Request $request, string $codigo): JsonResponse
    {
        return $this->ler($request, fn (Contexto $ctx): array => ['curriculo' => $this->detalhar($ctx, $this->localizador->localizar($ctx, $codigo))]);
    }

    /**
     * Cria ou edita o próprio. Com `candidatarA` (código da vaga), é o "Salvar e candidatar-se" do
     * formulário "Seu currículo para candidatura" (l.1006). A recusa da candidatura sai como 422 com
     * `titulo`, `quase` e `curriculoSalvo`.
     */
    #[Route('/api/curriculos/meu', name: 'trabalho_externo_curriculo_salvar', methods: ['POST'])]
    public function salvar(Request $request, SalvarCurriculoECandidatarUseCase $salvar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $salvar): array {
            $corpo = $this->corpo($request);
            $resultado = $salvar->executar($ctx, DadosDoCurriculo::deArray($corpo), is_string($corpo['candidatarA'] ?? null) ? $corpo['candidatarA'] : null);

            return ['curriculo' => $this->detalhar($ctx, $resultado->curriculo), 'toast' => $resultado->toast];
        });
    }

    #[Route('/api/curriculos/meu/arquivos/{tipo}', name: 'trabalho_externo_curriculo_arquivo', methods: ['POST'], requirements: ['tipo' => self::TIPO])]
    public function anexar(Request $request, string $tipo, AnexarArquivoAoCurriculoUseCase $anexar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $tipo, $anexar): array {
            $arquivo = $request->files->get('arquivo');

            return ['curriculo' => $this->detalhar($ctx, $anexar->executar($ctx, TipoArquivoDoCurriculo::from($tipo), $arquivo instanceof UploadedFile ? $arquivo : null))];
        });
    }

    #[Route('/api/curriculos/meu/arquivos/{tipo}/remover', name: 'trabalho_externo_curriculo_arquivo_remover', methods: ['POST'], requirements: ['tipo' => self::TIPO])]
    public function removerArquivo(Request $request, string $tipo, RemoverArquivoDoCurriculoUseCase $remover): JsonResponse
    {
        return $this->escrever($request, fn (Contexto $ctx): array => ['curriculo' => $this->detalhar($ctx, $remover->executar($ctx, TipoArquivoDoCurriculo::from($tipo)))]);
    }

    #[Route('/api/curriculos/meu/excluir', name: 'trabalho_externo_curriculo_excluir_meu', methods: ['POST'])]
    public function excluirMeu(Request $request, ExcluirMeuCurriculoUseCase $excluir): JsonResponse
    {
        return $this->escrever($request, static function (Contexto $ctx) use ($excluir): array {
            $excluir->executar($ctx);

            return [];
        });
    }

    #[Route('/api/curriculos/{codigo}/excluir', name: 'trabalho_externo_curriculo_excluir', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function excluir(Request $request, string $codigo, ExcluirCurriculoUseCase $excluir): JsonResponse
    {
        return $this->escrever($request, static function (Contexto $ctx) use ($codigo, $excluir): array {
            $excluir->executar($ctx, $codigo);

            return [];
        });
    }

    #[Route('/api/curriculos/{codigo}/status', name: 'trabalho_externo_curriculo_status', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function status(Request $request, string $codigo, MudarStatusDoCurriculoUseCase $mudar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $mudar): array {
            $status = StatusCurriculo::tryFrom((string) ($this->corpo($request)['status'] ?? '')) ?? throw new DadosInvalidos('Situação desconhecida.');

            return ['curriculo' => $this->detalhar($ctx, $mudar->executar($ctx, $codigo, $status))];
        });
    }

    #[Route('/api/curriculos/{codigo}/nota', name: 'trabalho_externo_curriculo_nota', methods: ['POST'], requirements: ['codigo' => self::CODIGO])]
    public function nota(Request $request, string $codigo, AnotarCurriculoUseCase $anotar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $codigo, $anotar): array {
            $nota = $this->corpo($request)['nota'] ?? '';
            $anotar->executar($ctx, $codigo, is_string($nota) ? $nota : '');

            return [];
        });
    }

    #[Route('/api/curriculos/marcacoes', name: 'trabalho_externo_curriculos_marcacoes', methods: ['POST'])]
    public function marcacoes(Request $request, MarcarCurriculosEmLoteUseCase $marcar): JsonResponse
    {
        return $this->escrever($request, function (Contexto $ctx) use ($request, $marcar): array {
            $corpo = $this->corpo($request);
            $op = OperacaoDeMarcacao::tryFrom((string) ($corpo['op'] ?? '')) ?? throw new DadosInvalidos('Operação desconhecida.');
            $codigos = is_array($corpo['codigos'] ?? null) ? array_values(array_map('strval', array_filter($corpo['codigos'], 'is_scalar'))) : [];

            return $marcar->executar($ctx, $codigos, $op);
        });
    }

    /** "Pré-visualizar" do formulário: o HTML dos dados ainda não salvos (erro em JSON, como as escritas). */
    #[Route('/api/curriculos/previa', name: 'trabalho_externo_curriculo_previa', methods: ['POST'])]
    public function previa(Request $request, PreVisualizarCurriculoUseCase $previa): Response
    {
        $html = null;
        $erro = $this->escrever($request, function (Contexto $ctx) use ($request, $previa, &$html): array {
            $html = $previa->executar($ctx, DadosDoCurriculo::deArray($this->corpo($request)));

            return [];
        });

        return is_string($html) ? $this->documentoHtml($html) : $erro;
    }

    #[Route('/curriculos/{codigo}/arquivos/{tipo}', name: 'trabalho_externo_curriculo_baixar', methods: ['GET'], requirements: ['codigo' => self::CODIGO, 'tipo' => self::TIPO])]
    public function baixar(Request $request, string $codigo, string $tipo, ArmazenamentoDeArquivos $armazenamento, EntregaDeArquivo $entrega): Response
    {
        $tipoDoArquivo = TipoArquivoDoCurriculo::from($tipo);
        $cv = $this->paraDownload($request, fn (Contexto $ctx): Curriculo => $this->localizador->localizar($ctx, $codigo));
        if (!$cv->temArquivo($tipoDoArquivo) || !$armazenamento->existe($chave = ChavesDeTrabalhoExterno::arquivoDoCurriculo($cv, $tipoDoArquivo))) {
            throw $this->createNotFoundException('Arquivo não encontrado.');
        }

        return $entrega->resposta($chave, (string) $cv->nomeDoArquivo($tipoDoArquivo), inline: $tipoDoArquivo === TipoArquivoDoCurriculo::Foto);
    }

    #[Route('/curriculos/{codigo}/documento', name: 'trabalho_externo_curriculo_documento', methods: ['GET'], requirements: ['codigo' => self::CODIGO])]
    public function documento(Request $request, string $codigo, GerarDocumentoDoCurriculoUseCase $gerar): Response
    {
        return $this->documentoHtml($this->paraDownload($request, static fn (Contexto $ctx): array => $gerar->executar($ctx, $codigo, false))['conteudo']);
    }

    #[Route('/curriculos/{codigo}/pdf', name: 'trabalho_externo_curriculo_pdf', methods: ['GET'], requirements: ['codigo' => self::CODIGO])]
    public function pdf(Request $request, string $codigo, GerarDocumentoDoCurriculoUseCase $gerar): Response
    {
        $doc = $this->paraDownload($request, static fn (Contexto $ctx): array => $gerar->executar($ctx, $codigo, true));

        return $this->anexo($doc['conteudo'], $doc['nome'], 'application/pdf');
    }

    /** "Word" do "Meu currículo" (desenho 06 de 24/10, l.223): só o próprio, sem a prévia `?como=`. */
    #[Route('/curriculos/meu/word', name: 'trabalho_externo_curriculo_word', methods: ['GET'])]
    public function word(GerarWordDoMeuCurriculoUseCase $gerar): Response
    {
        $ctx = $this->contextoOuNulo() ?? throw $this->createAccessDeniedException();
        try {
            $doc = $gerar->executar($ctx);
        } catch (DemandaNaoEncontrada) {
            throw $this->createNotFoundException();
        }

        return $this->anexo($doc['conteudo'], $doc['nome'], 'application/msword', $doc['nomeAscii']);
    }

    #[Route('/curriculos/exportar.csv', name: 'trabalho_externo_curriculos_csv', methods: ['GET'])]
    public function csv(Request $request, ExportarCurriculosCsvUseCase $exportar): Response
    {
        $filtros = [];
        foreach (self::FILTROS_DO_CSV as $chave) {
            $filtros[$chave] = trim((string) $request->query->get($chave, ''));
        }
        $csv = $this->paraDownload($request, static fn (Contexto $ctx): string => $exportar->executar($ctx, $filtros));

        return $this->anexo($csv, ExportarCurriculosCsvUseCase::NOME_DO_ARQUIVO, 'text/csv; charset=utf-8');
    }

    /**
     * A lista dos alertas por e-mail (KPI do painel, l.1146), só para o Master. A tela baixa por `fetch`:
     * a recusa e o "Ainda sem inscritos" (404) saem em JSON, com o título e o texto do toast do desenho.
     */
    #[Route('/curriculos/alertas.csv', name: 'trabalho_externo_alertas_csv', methods: ['GET'])]
    public function alertasCsv(Request $request, ExportarAlertasCsvUseCase $exportar): Response
    {
        $ctx = $this->contextoDeLeitura($request);
        if ($ctx === null) {
            return $this->semAcesso();
        }
        try {
            $csv = $exportar->executar($ctx);
        } catch (DemandaNaoEncontrada $e) {
            return $this->json(['ok' => false, 'titulo' => ExportarAlertasCsvUseCase::TITULO_SEM_INSCRITOS, 'mensagem' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (AcaoNaoPermitida $e) {
            return $this->json(['ok' => false, 'mensagem' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        }

        return $this->anexo($csv, ExportarAlertasCsvUseCase::NOME_DO_ARQUIVO, 'text/csv; charset=utf-8');
    }

    /** @return array<string, mixed> */
    private function detalhar(Contexto $ctx, Curriculo $cv): array
    {
        return $this->detalhe->detalhar($ctx, $cv);
    }

    /**
     * As leituras que não respondem JSON (arquivo, documento, PDF, CSV): o mesmo contexto das leituras,
     * com "não encontrado" em 404 e "sem permissão" em 403.
     *
     * @template T
     *
     * @param callable(Contexto): T $acao
     *
     * @return T
     */
    private function paraDownload(Request $request, callable $acao): mixed
    {
        $ctx = $this->contextoDeLeitura($request) ?? throw $this->createAccessDeniedException();
        try {
            return $acao($ctx);
        } catch (DemandaNaoEncontrada) {
            throw $this->createNotFoundException();
        } catch (AcaoNaoPermitida $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
    }

    private function documentoHtml(string $html): Response
    {
        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            // O documento só desenha: sem script, só imagens e estilos do próprio BlueJus.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ]);
    }

    /** `$nomeAscii`: o nome para navegador antigo, obrigatório quando `$nome` tem acento. */
    private function anexo(string $conteudo, string $nome, string $tipo, string $nomeAscii = ''): Response
    {
        $resposta = new Response($conteudo, Response::HTTP_OK, ['Content-Type' => $tipo, 'X-Content-Type-Options' => 'nosniff']);
        $resposta->headers->set('Content-Disposition', $resposta->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nome, $nomeAscii));

        return $resposta;
    }

    private function contextoDeLeitura(Request $request): ?Contexto
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return null;
        }
        $como = $this->previa->perfilPedido($ctx, $request->query->get('como'));

        return $como !== null ? $this->previa->contexto($ctx, $como) : $ctx;
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function ler(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoDeLeitura($request);

        return $ctx === null ? $this->semAcesso() : $this->responder(static fn (): array => $acao($ctx));
    }

    /** @param callable(Contexto): array<string, mixed> $acao */
    private function escrever(Request $request, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(static fn (): array => $acao($ctx));
    }
}
