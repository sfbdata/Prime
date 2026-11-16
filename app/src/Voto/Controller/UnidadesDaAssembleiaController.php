<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\DadosDaUnidadeInput;
use App\Voto\DTO\ResultadoDoEnvioDeLinks;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\AdicionarUnidadeUseCase;
use App\Voto\UseCase\EditarUnidadeUseCase;
use App\Voto\UseCase\EnviarLinksPorEmailUseCase;
use App\Voto\UseCase\GerarNovoLinkDaUnidadeUseCase;
use App\Voto\UseCase\ImportarUnidadesUseCase;
use App\Voto\UseCase\ObterUnidadeParaEdicaoUseCase;
use App\Voto\UseCase\ObterUnidadesDaAssembleiaUseCase;
use App\Voto\UseCase\PrevisualizarImportacaoDeUnidadesUseCase;
use App\Voto\UseCase\RemoverUnidadeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Unidades e links" da assembleia (spec voto-assembleia-condominio, seções 1.4 e 1.6): colar a lista de unidades
 * (prévia com os erros por linha antes de gravar), adicionar, editar e remover unidade, copiar link e mensagem, gerar novo link e enviar os links
 * por e-mail em lotes de 25.
 */
#[Route('/voto/assembleias/{id}/unidades', requirements: ['id' => '\d+', 'unidade' => '\d+'])]
final class UnidadesDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly AvisosDoVoto $avisos,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('', name: 'voto_assembleia_unidades', methods: ['GET'])]
    public function unidades(int $id, ObterUnidadesDaAssembleiaUseCase $obter): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $tela = $obter->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/unidades.html.twig', [
            'tela' => $tela,
            'assembleia' => $tela->assembleia,
            'relatorio' => $this->avisos->retirarRelatorio(),
        ]);
    }

    /** Lê a planilha ou o texto colado e mostra a prévia (linhas lidas e erros por linha). Não grava nada. */
    #[Route('/importar', name: 'voto_assembleia_unidades_importar', methods: ['POST'])]
    public function importar(
        int $id,
        Request $request,
        PrevisualizarImportacaoDeUnidadesUseCase $previsualizar,
        ObterUnidadesDaAssembleiaUseCase $obter,
    ): Response {
        $tenant = $this->guarda->exigir();
        $texto = (string) $request->request->get('lista', '');
        $arquivo = $request->files->get('arquivo');
        $previa = null;
        $this->executar($request, $id, function () use ($previsualizar, $tenant, $id, $texto, $arquivo, &$previa): void {
            if (null !== $arquivo && !$arquivo instanceof UploadedFile) {
                throw new OperacaoInvalidaNaAssembleiaException('Selecione um arquivo .xlsx ou .csv.');
            }
            $previa = $arquivo instanceof UploadedFile
                ? $previsualizar->executarArquivo($tenant, $id, $arquivo)
                : $previsualizar->executar($tenant, $id, $texto);
        });
        if (null === $previa) {
            return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
        }
        if ([] === $previa->validas && [] === $previa->invalidas && [] === $previa->duplicadas) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.unidades.nenhuma_valida'));

            return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
        }
        $tela = $obter->executar($tenant, $id);

        return $this->render('voto/assembleia/unidades.html.twig', [
            'tela' => $tela, 'assembleia' => $tela->assembleia, 'relatorio' => null, 'previa' => $previa,
        ]);
    }

    /** Confirma a prévia: grava o texto já lido e limpo, pelo mesmo caminho da importação. */
    #[Route('/importar/confirmar', name: 'voto_assembleia_unidades_importar_confirmar', methods: ['POST'])]
    public function confirmar(int $id, Request $request, ImportarUnidadesUseCase $importar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $texto = (string) $request->request->get('lista', '');
        $this->executar($request, $id, function () use ($importar, $tenant, $id, $texto): void {
            $resultado = $importar->executar($tenant, $id, $texto);
            $this->avisos->guardarRelatorio(['invalidas' => $resultado->invalidas, 'duplicadas' => $resultado->duplicadas]);
            if (0 === $resultado->importadas) {
                $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.unidades.nenhuma_valida'));

                return;
            }
            $this->avisos->ok($this->tradutor->trans('voto.assembleia.gestor.unidades.importadas', [
                'total' => $resultado->importadas, 'invalidas' => \count($resultado->invalidas), 'duplicadas' => \count($resultado->duplicadas),
            ]));
        });

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
    }

    #[Route('/adicionar', name: 'voto_assembleia_unidade_adicionar', methods: ['POST'])]
    public function adicionar(int $id, Request $request, AdicionarUnidadeUseCase $adicionar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, function () use ($adicionar, $tenant, $id, $request): void {
            $adicionar->executar($tenant, $id, $this->dadosDoFormulario($request));
            $this->avisar('voto.assembleia.gestor.aviso.unidade_adicionada');
        });

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
    }

    #[Route('/{unidade}/editar', name: 'voto_assembleia_unidade_editar', methods: ['GET', 'POST'])]
    public function editar(
        int $id,
        int $unidade,
        Request $request,
        ObterUnidadeParaEdicaoUseCase $obter,
        EditarUnidadeUseCase $editar,
    ): Response {
        $tenant = $this->guarda->exigir();
        try {
            $tela = $obter->executar($tenant, $id, $unidade);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
        $erro = null;
        $dados = $tela->dados;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
                $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

                return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
            }
            $dados = $this->dadosDoFormulario($request);
            try {
                $editar->executar($tenant, $id, $unidade, $dados);
                $this->avisar('voto.assembleia.gestor.aviso.unidade_editada');

                return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id, '_fragment' => 'unidade-' . $unidade]);
            } catch (OperacaoInvalidaNaAssembleiaException $e) {
                $erro = $e->getMessage();
            } catch (AssembleiaNaoEncontradaException) {
                throw $this->createNotFoundException();
            }
        }

        return $this->render('voto/assembleia/unidade_editar.html.twig', [
            'tela' => $tela, 'assembleia' => $tela->assembleia, 'dados' => $dados, 'erro' => $erro,
        ], new Response('', null === $erro ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/modelo.xlsx', name: 'voto_assembleia_unidades_modelo', methods: ['GET'])]
    public function modelo(ArquivoDeUnidades $arquivos): Response
    {
        $this->guarda->exigir();

        return $arquivos->modelo();
    }

    #[Route('/{unidade}/remover', name: 'voto_assembleia_unidade_remover', methods: ['POST'])]
    public function remover(int $id, int $unidade, Request $request, RemoverUnidadeUseCase $remover): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, function () use ($remover, $tenant, $id, $unidade): void {
            $remover->executar($tenant, $id, $unidade);
            $this->avisar('voto.assembleia.gestor.aviso.unidade_removida');
        });

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
    }

    #[Route('/{unidade}/novo-link', name: 'voto_assembleia_unidade_novo_link', methods: ['POST'])]
    public function novoLink(int $id, int $unidade, Request $request, GerarNovoLinkDaUnidadeUseCase $gerar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, function () use ($gerar, $tenant, $id, $unidade): void {
            $gerar->executar($tenant, $id, $unidade);
            $this->avisar('voto.assembleia.gestor.aviso.novo_link');
        });

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id, '_fragment' => 'unidade-' . $unidade]);
    }

    #[Route('/enviar-links', name: 'voto_assembleia_unidades_enviar', methods: ['POST'])]
    public function enviar(int $id, Request $request, EnviarLinksPorEmailUseCase $enviar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, fn () => $this->avisarEnvio($enviar->executar($tenant, $id)));

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id]);
    }

    #[Route('/{unidade}/reenviar', name: 'voto_assembleia_unidade_reenviar', methods: ['POST'])]
    public function reenviar(int $id, int $unidade, Request $request, EnviarLinksPorEmailUseCase $enviar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $this->executar($request, $id, fn () => $this->avisarEnvio($enviar->executar($tenant, $id, $unidade)));

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id, '_fragment' => 'unidade-' . $unidade]);
    }

    private function dadosDoFormulario(Request $request): DadosDaUnidadeInput
    {
        $campo = static fn (string $nome): string => (string) $request->request->get($nome, '');

        return new DadosDaUnidadeInput(
            identificacao: $campo('identificacao'),
            bloco: $campo('bloco'),
            situacao: $campo('situacao'),
            fracao: $campo('fracao'),
            responsavel: $campo('responsavel'),
            email: $campo('email'),
        );
    }

    private function avisar(string $chave): void
    {
        $this->avisos->ok($this->tradutor->trans($chave));
    }

    private function avisarEnvio(ResultadoDoEnvioDeLinks $envio): void
    {
        $texto = $this->tradutor->trans('voto.assembleia.gestor.links.enviados', [
            'enviados' => $envio->enviados, 'falhas' => $envio->falhas, 'faltam' => $envio->faltam,
        ]);
        if ($envio->falhas > 0) {
            $this->avisos->erro($texto);

            return;
        }
        $this->avisos->ok($texto);
    }

    private function executar(Request $request, int $id, callable $acao): void
    {
        if (!$this->isCsrfTokenValid('voto_assembleia_' . $id, (string) $request->request->get('_token'))) {
            $this->avisos->erro($this->tradutor->trans('voto.assembleia.gestor.aviso.sessao_expirada'));

            return;
        }
        try {
            $acao();
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
    }
}
