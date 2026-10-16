<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\Fontes\ArquivoNaoReconhecido;
use App\Master\Fontes\FonteIndisponivel;
use App\Master\Fontes\FontesPublicas;
use App\Master\UseCase\AtualizarFontesPublicasUseCase;
use App\Master\UseCase\ImportarArquivoDeFontesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dados públicos do mapa (spec perfis-master D8): o navegador pede ao servidor, o servidor busca no
 * IBGE/OAB, guarda o retrato com fonte e data e devolve. Nenhuma chamada externa sai do navegador.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class FontesPublicasController extends AbstractController
{
    public const CSRF = 'master_fontes';

    public function __construct(
        private readonly FontesPublicas $fontes,
    ) {
    }

    #[Route('/master/ibge/municipios/{uf}', name: 'master_ibge_municipios', requirements: ['uf' => '[A-Za-z]{2}'], methods: ['GET'])]
    public function municipios(string $uf): Response
    {
        try {
            $json = $this->fontes->municipios($uf);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        } catch (FonteIndisponivel) {
            return $this->json(['erro' => 'Não foi possível carregar os municípios do IBGE agora.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $resposta = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json']);
        $resposta->setPrivate();
        $resposta->setMaxAge(86400);

        return $resposta;
    }

    #[Route('/master/ibge/populacao/{uf}', name: 'master_ibge_populacao', requirements: ['uf' => '[A-Za-z]{2}'], methods: ['GET'])]
    public function populacao(string $uf): JsonResponse
    {
        try {
            return $this->json($this->fontes->populacaoDosMunicipios($uf));
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        } catch (FonteIndisponivel) {
            return $this->json(['erro' => 'Não foi possível acessar o IBGE agora.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/master/fontes', name: 'master_fontes_resumo', methods: ['GET'])]
    public function resumo(): JsonResponse
    {
        return $this->json($this->fontes->resumo());
    }

    #[Route('/master/fontes/atualizar', name: 'master_fontes_atualizar', methods: ['POST'])]
    public function atualizar(Request $request, AtualizarFontesPublicasUseCase $useCase): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        $resultado = $useCase->executar($request->request->getBoolean('automatico'));

        return $this->json(['resultado' => $resultado, 'resumo' => $this->fontes->resumo()]);
    }

    #[Route('/master/fontes/tabela', name: 'master_fontes_tabela', methods: ['POST'])]
    public function tabela(Request $request, ImportarArquivoDeFontesUseCase $useCase): JsonResponse
    {
        return $this->receberArquivo($request, fn (string $conteudo, string $nome): array => $useCase->tabela($conteudo, $nome));
    }

    #[Route('/master/fontes/oab', name: 'master_fontes_oab', methods: ['POST'])]
    public function quadroOab(Request $request, ImportarArquivoDeFontesUseCase $useCase): JsonResponse
    {
        return $this->receberArquivo($request, fn (string $conteudo, string $nome): array => $useCase->quadroOab($conteudo, $nome));
    }

    /**
     * @param callable(string, string): array<string, mixed> $importar
     */
    private function receberArquivo(Request $request, callable $importar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        $arquivo = $request->files->get('arquivo');
        if (!$arquivo instanceof UploadedFile || !$arquivo->isValid()) {
            return $this->json(['erro' => 'Escolha um arquivo CSV no formato do modelo.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($arquivo->getSize() > ImportarArquivoDeFontesUseCase::TAMANHO_MAXIMO) {
            return $this->json(['erro' => 'Arquivo grande demais (máximo 2 MB). Use o modelo baixado pelo menu.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $nome = mb_substr(basename($arquivo->getClientOriginalName()), 0, 120);
        if (strtolower(pathinfo($nome, \PATHINFO_EXTENSION)) !== 'csv') {
            return $this->json(['erro' => 'Envie CSV ou Excel no formato do modelo baixado pelo menu (o Excel é convertido no navegador). PDF ainda não é lido.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $resultado = $importar((string) file_get_contents($arquivo->getPathname()), $nome);
        } catch (ArquivoNaoReconhecido $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['resultado' => $resultado, 'resumo' => $this->fontes->resumo()]);
    }
}
