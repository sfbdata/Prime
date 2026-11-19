<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Entity\Auth\User;
use App\Voto\DTO\ResultadoDoEnvioDeLinks;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Exception\SemPapelNaAvaliacaoException;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\EditarUnidadeUseCase;
use App\Voto\UseCase\EnviarLinksPorEmailUseCase;
use App\Voto\UseCase\GerarNovoLinkDaUnidadeUseCase;
use App\Voto\UseCase\ImportarUnidadesUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\UseCase\ObterUnidadesDaAssembleiaUseCase;
use App\Voto\UseCase\RegistrarOcorrenciaUseCase;
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
 * (relatório de inválidas e duplicadas), remover unidade, copiar link e mensagem, gerar novo link e enviar os links
 * por e-mail em lotes de 25, e registrar uma ocorrência da unidade (spec voto-avaliacoes-do-conselho-1611).
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

    #[Route('/importar', name: 'voto_assembleia_unidades_importar', methods: ['POST'])]
    public function importar(int $id, Request $request, ImportarUnidadesUseCase $importar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $texto = (string) $request->request->get('lista', '');
        $arquivo = $request->files->get('arquivo');
        $this->executar($request, $id, function () use ($importar, $tenant, $id, $texto, $arquivo): void {
            if (null !== $arquivo && !$arquivo instanceof UploadedFile) {
                throw new OperacaoInvalidaNaAssembleiaException('Selecione um arquivo .xlsx ou .csv.');
            }
            $resultado = $arquivo instanceof UploadedFile
                ? $importar->executarArquivo($tenant, $id, $arquivo)
                : $importar->executar($tenant, $id, $texto);
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

    #[Route('/modelo.xlsx', name: 'voto_assembleia_unidades_modelo', methods: ['GET'])]
    public function modelo(int $id, ArquivoDeUnidades $arquivos, ObterAssembleiaUseCase $obter): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $cenario = $obter->executar($tenant, $id)->cenario;
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $arquivos->modelo($cenario);
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

    #[Route('/{unidade}/editar', name: 'voto_assembleia_unidade_editar', methods: ['POST'])]
    public function editar(int $id, int $unidade, Request $request, EditarUnidadeUseCase $editar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $campos = [];
        foreach (['identificacao', 'bloco', 'situacao', 'fracao', 'responsavel', 'email', 'telefone'] as $campo) {
            $campos[$campo] = $request->request->getString($campo);
        }
        $this->executar($request, $id, function () use ($editar, $tenant, $id, $unidade, $campos): void {
            $editar->executar($tenant, $id, $unidade, $campos);
            $this->avisar('voto.assembleia.gestor.unidades.editada');
        });

        return $this->redirectToRoute('voto_assembleia_unidades', ['id' => $id, '_fragment' => 'unidade-' . $unidade]);
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

    #[Route('/{unidade}/ocorrencia', name: 'voto_assembleia_unidade_ocorrencia', methods: ['POST'])]
    public function ocorrencia(int $id, int $unidade, Request $request, RegistrarOcorrenciaUseCase $registrar): RedirectResponse
    {
        $tenant = $this->guarda->exigir();
        $usuario = $this->getUser();
        $texto = (string) $request->request->get('ocorrencia', '');
        $por = $this->guarda->nomeDeQuemOpera();
        $this->executar($request, $id, function () use ($registrar, $tenant, $id, $unidade, $texto, $usuario, $por): void {
            if (!$usuario instanceof User) {
                throw $this->createAccessDeniedException();
            }
            try {
                $registrar->executar($tenant, $id, $unidade, $texto, $usuario, $por);
            } catch (SemPapelNaAvaliacaoException) {
                throw $this->createAccessDeniedException($this->tradutor->trans('voto.equipe.recusa.somente_leitura'));
            }
            $this->avisar('voto.assembleia.gestor.ocorrencia.registrada');
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
