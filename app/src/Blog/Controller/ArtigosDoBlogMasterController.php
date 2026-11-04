<?php

declare(strict_types=1);

namespace App\Blog\Controller;

use App\Blog\Armazenamento\ChavesDoBlog;
use App\Blog\DTO\SalvarArtigoDoBlogInput;
use App\Blog\Exception\ArtigoDoBlogNaoEncontrado;
use App\Blog\Exception\ArtigoDoBlogNaoPublicavel;
use App\Blog\Exception\ArtigoDoBlogRecusado;
use App\Blog\Exception\ImagemDoArtigoNaoEncontrada;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Service\CatalogoDoBlog;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\UseCase\CarregarArtigoParaEditorUseCase;
use App\Blog\UseCase\DespublicarArtigoDoBlogUseCase;
use App\Blog\UseCase\DuplicarArtigoDoBlogUseCase;
use App\Blog\UseCase\EnviarImagemDoArtigoUseCase;
use App\Blog\UseCase\ExcluirArtigoDoBlogUseCase;
use App\Blog\UseCase\ListarArtigosDoBlogUseCase;
use App\Blog\UseCase\ListarPublicacoesDosBlogsUseCase;
use App\Blog\UseCase\ObterImagemDoArtigoUseCase;
use App\Blog\UseCase\PublicarArtigoDoBlogUseCase;
use App\Blog\UseCase\SalvarArtigoDoBlogUseCase;
use App\Blog\UseCase\SugerirMarcadoresUseCase;
use App\Blog\UseCase\VerificarArtigoDoBlogUseCase;
use App\Entity\Auth\User;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\EntregaDeArquivo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * "Artigos dos blogs" do Painel do Master (spec painel-artigos-blogs, fatia P2): a lista (JSON, vira seção do painel),
 * a página do editor, salvar rascunho, verificar, duplicar, excluir, sugestões de marcadores e a prévia aproximada; e,
 * desde a P3, o envio e a entrega das imagens do corpo e da capa (seção 4). Só o Master. Todo POST leva o token CSRF `master_blog` no header `X-CSRF-Token`.
 *
 * Desde a P4: Publicar e Despublicar (pedem a geração dos blogs ao builder) e o histórico das gerações para a faixa da
 * lista. Agendar fica para a P5 (agendador).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/blog')]
final class ArtigosDoBlogMasterController extends AbstractController
{
    public const TOKEN = 'master_blog';

    public function __construct(private readonly CsrfTokenManagerInterface $tokens)
    {
    }

    #[Route('/artigos', name: 'master_blog_artigos_listar', methods: ['GET'])]
    public function listar(Request $request, ListarArtigosDoBlogUseCase $listar): JsonResponse
    {
        return $this->json($listar->executar(
            $request->query->getString('busca') ?: null,
            $request->query->getString('situacao') ?: null,
            $request->query->getString('blogs') ?: null,
            $request->query->getInt('pagina', 1),
        ));
    }

    #[Route('/artigos/novo', name: 'master_blog_artigos_novo', methods: ['GET'])]
    public function novo(CarregarArtigoParaEditorUseCase $carregar): Response
    {
        return $this->editor($carregar, null);
    }

    #[Route('/artigos/{id}', name: 'master_blog_artigos_editar', requirements: ['id' => '\d{1,10}'], methods: ['GET'])]
    public function editar(int $id, CarregarArtigoParaEditorUseCase $carregar): Response
    {
        return $this->editor($carregar, $id);
    }

    #[Route('/artigos/{id}/previa', name: 'master_blog_artigos_previa', requirements: ['id' => '\d{1,10}'], methods: ['GET'])]
    public function previa(int $id, CarregarArtigoParaEditorUseCase $carregar, HtmlDoArtigoParaMarkdown $conversor): Response
    {
        try {
            $artigo = $carregar->executar($id);
        } catch (ArtigoDoBlogNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        return $this->render('blog/previa.html.twig', [
            'artigo' => $artigo,
            'categoria' => CatalogoDoBlog::nomeDaCategoria($artigo->categoria) ?? '',
            'autor' => CatalogoDoBlog::nomeDoAutor($artigo->autor) ?? '',
            'corpo' => $conversor->sanitizar($artigo->corpoHtml),
        ]);
    }

    #[Route('/artigos', name: 'master_blog_artigos_criar', methods: ['POST'])]
    public function criar(Request $request, SalvarArtigoDoBlogUseCase $salvar, ValidatorInterface $validador): JsonResponse
    {
        return $this->salvarArtigo($request, null, $salvar, $validador);
    }

    #[Route('/artigos/{id}', name: 'master_blog_artigos_salvar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function salvar(int $id, Request $request, SalvarArtigoDoBlogUseCase $salvar, ValidatorInterface $validador): JsonResponse
    {
        return $this->salvarArtigo($request, $id, $salvar, $validador);
    }

    #[Route('/artigos/{id}/verificar', name: 'master_blog_artigos_verificar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function verificar(int $id, Request $request, VerificarArtigoDoBlogUseCase $verificar): JsonResponse
    {
        return $this->protegido($request, static fn (): JsonResponse => new JsonResponse(['erros' => $verificar->executar($id)]));
    }

    #[Route('/artigos/{id}/duplicar', name: 'master_blog_artigos_duplicar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function duplicar(int $id, Request $request, DuplicarArtigoDoBlogUseCase $duplicar): JsonResponse
    {
        $ator = $this->getUser();

        return $this->protegido($request, fn (): JsonResponse => $this->json([
            'ok' => true,
            'id' => $duplicar->executar($id, $ator instanceof User ? $ator : null)->getId(),
        ]));
    }

    #[Route('/artigos/{id}/excluir', name: 'master_blog_artigos_excluir', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function excluir(int $id, Request $request, ExcluirArtigoDoBlogUseCase $excluir): JsonResponse
    {
        return $this->protegido($request, function () use ($id, $excluir): JsonResponse {
            $excluir->executar($id);

            return $this->json(['ok' => true]);
        });
    }

    /** Publicar agora: 422 com os erros por campo quando o artigo não passa nas regras do zod; 409 se já está no ar sem alterações. */
    #[Route('/artigos/{id}/publicar', name: 'master_blog_artigos_publicar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function publicar(int $id, Request $request, PublicarArtigoDoBlogUseCase $publicar): JsonResponse
    {
        $ator = $this->getUser();

        return $this->protegido($request, fn (): JsonResponse => $this->json(['ok' => true] + (array) $publicar->executar($id, $ator instanceof User ? $ator : null)));
    }

    /** Despublicar: 409 se o artigo não está publicado. */
    #[Route('/artigos/{id}/despublicar', name: 'master_blog_artigos_despublicar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function despublicar(int $id, Request $request, DespublicarArtigoDoBlogUseCase $despublicar): JsonResponse
    {
        $ator = $this->getUser();

        return $this->protegido($request, fn (): JsonResponse => $this->json(['ok' => true] + (array) $despublicar->executar($id, $ator instanceof User ? $ator : null)));
    }

    /** As últimas gerações dos blogs (faixa e histórico da lista). */
    #[Route('/publicacoes', name: 'master_blog_publicacoes', methods: ['GET'])]
    public function publicacoes(ListarPublicacoesDosBlogsUseCase $listar): JsonResponse
    {
        return $this->json($listar->executar());
    }

    /** Envio do TinyMCE e do cartão da capa: multipart `arquivo` (e `alt`, opcional). Devolve `{id, url}`. */
    #[Route('/artigos/{id}/imagens', name: 'master_blog_imagens_enviar', requirements: ['id' => '\d{1,10}'], methods: ['POST'])]
    public function enviarImagem(int $id, Request $request, EnviarImagemDoArtigoUseCase $enviar): JsonResponse
    {
        return $this->protegido($request, function () use ($id, $request, $enviar): JsonResponse {
            $arquivo = $request->files->get('arquivo');
            if (!$arquivo instanceof UploadedFile) {
                throw ArtigoDoBlogRecusado::imagem('Escolha uma imagem para enviar.');
            }
            $alt = $request->request->get('alt');
            $imagem = $enviar->executar($id, $arquivo, \is_string($alt) ? $alt : null);

            return $this->json([
                'id' => $imagem->getId(),
                'url' => $this->generateUrl('master_blog_imagens_ver', ['id' => $imagem->getId()]),
            ], Response::HTTP_CREATED);
        });
    }

    /** A imagem no editor (o `src` do corpo e a prévia da capa). Só o Master; fora do editor ninguém a enxerga. */
    #[Route('/imagens/{id}', name: 'master_blog_imagens_ver', requirements: ['id' => '\d{1,10}'], methods: ['GET'])]
    public function verImagem(int $id, ObterImagemDoArtigoUseCase $obter, ArmazenamentoDeArquivos $armazenamento, EntregaDeArquivo $entrega): Response
    {
        try {
            $imagem = $obter->executar($id);
        } catch (ImagemDoArtigoNaoEncontrada $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        $chave = ChavesDoBlog::imagem($imagem);
        if (!$armazenamento->existe($chave)) {
            throw $this->createNotFoundException('Arquivo da imagem não encontrado.');
        }

        return $entrega->resposta($chave, $imagem->getNomeOriginal(), inline: true);
    }

    #[Route('/marcadores', name: 'master_blog_marcadores', methods: ['GET'])]
    public function marcadores(Request $request, SugerirMarcadoresUseCase $sugerir): JsonResponse
    {
        return $this->json($sugerir->executar($request->query->getString('busca') ?: null));
    }

    private function editor(CarregarArtigoParaEditorUseCase $carregar, ?int $id): Response
    {
        try {
            $artigo = $carregar->executar($id);
        } catch (ArtigoDoBlogNaoEncontrado $e) {
            throw $this->createNotFoundException($e->getMessage());
        }

        return $this->render('blog/editor.html.twig', [
            'artigo' => $artigo,
            'categorias' => CatalogoDoBlog::CATEGORIAS,
            'autores' => CatalogoDoBlog::AUTORES,
            'blogs' => array_map(static fn (BlogsDoArtigo $b): array => ['valor' => $b->value, 'rotulo' => $b->rotulo()], BlogsDoArtigo::cases()),
            'token' => $this->tokens->getToken(self::TOKEN)->getValue(),
        ]);
    }

    private function salvarArtigo(Request $request, ?int $id, SalvarArtigoDoBlogUseCase $salvar, ValidatorInterface $validador): JsonResponse
    {
        return $this->protegido($request, function () use ($request, $id, $salvar, $validador): JsonResponse {
            $corpo = json_decode($request->getContent(), true);
            $ator = $this->getUser();
            $input = SalvarArtigoDoBlogInput::doJson($ator instanceof User ? $ator : null, \is_array($corpo) ? $corpo : [], $id);

            $erros = [];
            foreach ($validador->validate($input) as $violacao) {
                $erros[$violacao->getPropertyPath()][] = (string) $violacao->getMessage();
            }
            if ($erros !== []) {
                return $this->json(['erros' => $erros], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $saida = $salvar->executar($input);

            return $this->json(['ok' => true, 'id' => $saida->id, 'leitura' => $saida->leitura, 'alteracoesPendentes' => $saida->alteracoesPendentes]);
        });
    }

    /** @param callable(): JsonResponse $acao */
    private function protegido(Request $request, callable $acao): JsonResponse
    {
        if (!$this->tokens->isTokenValid(new CsrfToken(self::TOKEN, (string) $request->headers->get('X-CSRF-Token')))) {
            return $this->json(['erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $acao();
        } catch (ArtigoDoBlogNaoEncontrado $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (ArtigoDoBlogNaoPublicavel $e) {
            return $this->json(['erros' => $e->erros], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ArtigoDoBlogRecusado $e) {
            return $this->json(['erros' => [$e->campo => [$e->getMessage()]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\DomainException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }
}
