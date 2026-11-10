<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\EntradaDoRascunho;
use App\AssinaturaEletronica\DTO\SelecaoDeDocumentos;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\CancelarEnvioUseCase;
use App\AssinaturaEletronica\UseCase\DuplicarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\EnviarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\EnviarLembreteUseCase;
use App\AssinaturaEletronica\UseCase\ExcluirRascunhosUseCase;
use App\AssinaturaEletronica\UseCase\ListarDocumentosUseCase;
use App\AssinaturaEletronica\UseCase\MoverParaLixeiraUseCase;
use App\AssinaturaEletronica\UseCase\ObterDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\PintarDocumentosUseCase;
use App\AssinaturaEletronica\UseCase\RestaurarDaLixeiraUseCase;
use App\AssinaturaEletronica\UseCase\SalvarRascunhoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O JSON da tela Assinatura (contrato da etapa 1, seção 4): lista, detalhe, salvar rascunho e as ações da lista; e, na
 * etapa 2 (seção 5.1), enviar, lembrete e cancelar o envio. POST
 * com CSRF no cabeçalho X-CSRF-Token (injetado pelo base.html.twig). Sem o módulo: 403; documento de outro escritório:
 * 404; ação que a situação não permite: 422 com `{erro}`.
 */
final class DocumentosDaAssinaturaController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinatura/documentos', name: 'assinatura_eletronica_documentos', methods: ['GET'])]
    public function documentos(ListarDocumentosUseCase $listar): JsonResponse
    {
        return $this->comAcesso(null, static fn (User $eu, Tenant $tenant): array => $listar->executar($tenant));
    }

    #[Route('/assinatura/documentos/{id}', name: 'assinatura_eletronica_documento', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documento(int $id, ObterDocumentoUseCase $obter): JsonResponse
    {
        return $this->comAcesso(null, static fn (User $eu, Tenant $tenant): array => $obter->executar($tenant, $id));
    }

    #[Route('/assinatura/documentos', name: 'assinatura_eletronica_criar', methods: ['POST'])]
    public function criar(Request $request, SalvarRascunhoUseCase $salvar): JsonResponse
    {
        return $this->comAcesso($request, fn (User $eu, Tenant $tenant): array => [
            'id' => $salvar->executar($eu, $tenant, null, $this->entradaDoRascunho($request)),
        ], Response::HTTP_CREATED);
    }

    #[Route('/assinatura/documentos/{id}', name: 'assinatura_eletronica_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(int $id, Request $request, SalvarRascunhoUseCase $salvar): JsonResponse
    {
        return $this->comAcesso($request, fn (User $eu, Tenant $tenant): array => [
            'id' => $salvar->executar($eu, $tenant, $id, $this->entradaDoRascunho($request)),
        ]);
    }

    #[Route('/assinatura/documentos/{id}/duplicar', name: 'assinatura_eletronica_duplicar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicar(int $id, Request $request, DuplicarDocumentoUseCase $duplicar): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => [
            'id' => $duplicar->executar($eu, $tenant, $id),
        ], Response::HTTP_CREATED);
    }

    #[Route('/assinatura/documentos/lixeira', name: 'assinatura_eletronica_lixeira', methods: ['POST'])]
    public function lixeira(Request $request, MoverParaLixeiraUseCase $mover): JsonResponse
    {
        return $this->comAcesso($request, static function (User $eu, Tenant $tenant) use ($request, $mover): array {
            $mover->executar($eu, $tenant, SelecaoDeDocumentos::deJson($request->toArray())->ids);

            return ['ok' => true];
        });
    }

    #[Route('/assinatura/documentos/restaurar', name: 'assinatura_eletronica_restaurar', methods: ['POST'])]
    public function restaurar(Request $request, RestaurarDaLixeiraUseCase $restaurar): JsonResponse
    {
        return $this->comAcesso($request, static function (User $eu, Tenant $tenant) use ($request, $restaurar): array {
            $restaurar->executar($eu, $tenant, SelecaoDeDocumentos::deJson($request->toArray())->ids);

            return ['ok' => true];
        });
    }

    #[Route('/assinatura/documentos/excluir', name: 'assinatura_eletronica_excluir', methods: ['POST'])]
    public function excluir(Request $request, ExcluirRascunhosUseCase $excluir): JsonResponse
    {
        return $this->comAcesso($request, static function (User $eu, Tenant $tenant) use ($request, $excluir): array {
            $excluir->executar($tenant, SelecaoDeDocumentos::deJson($request->toArray())->ids);

            return ['ok' => true];
        });
    }

    #[Route('/assinatura/documentos/cor', name: 'assinatura_eletronica_cor', methods: ['POST'])]
    public function cor(Request $request, PintarDocumentosUseCase $pintar): JsonResponse
    {
        return $this->comAcesso($request, static function (User $eu, Tenant $tenant) use ($request, $pintar): array {
            $selecao = SelecaoDeDocumentos::deJson($request->toArray());
            $pintar->executar($tenant, $selecao->ids, $selecao->cor ?? throw new AcaoRecusada('assinatura_eletronica.erro.cor_invalida'));

            return ['ok' => true];
        });
    }

    #[Route('/assinatura/documentos/{id}/enviar', name: 'assinatura_eletronica_enviar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enviar(int $id, Request $request, EnviarDocumentoUseCase $enviar): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => [
            'ok' => true,
            'documento' => $enviar->executar($eu, $tenant, $id),
        ]);
    }

    #[Route('/assinatura/documentos/{id}/lembrete', name: 'assinatura_eletronica_lembrete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lembrete(int $id, Request $request, EnviarLembreteUseCase $lembrete): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => $lembrete->executar($eu, $tenant, $id));
    }

    #[Route('/assinatura/documentos/{id}/cancelar', name: 'assinatura_eletronica_cancelar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cancelar(int $id, Request $request, CancelarEnvioUseCase $cancelar): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => [
            'ok' => true,
            'documento' => $cancelar->executar($eu, $tenant, $id),
        ]);
    }

    /**
     * Porta comum: módulo, CSRF (só nos POST) e as recusas de domínio como JSON.
     *
     * @param \Closure(User, Tenant): array<string, mixed> $acao
     */
    private function comAcesso(?Request $post, \Closure $acao, int $status = Response::HTTP_OK): JsonResponse
    {
        $quem = $this->acesso->quemPode();
        if ($quem === null) {
            return $this->erro('assinatura_eletronica.erro.sem_permissao', [], Response::HTTP_FORBIDDEN);
        }

        if ($post !== null) {
            $this->validarCsrfAjax($post);
        }

        try {
            return $this->json($acao(...$quem), $status);
        } catch (DocumentoNaoEncontrado) {
            return $this->erro('assinatura_eletronica.erro.nao_encontrado', [], Response::HTTP_NOT_FOUND);
        } catch (AcaoRecusada $e) {
            return $this->erro($e->getMessage(), $e->parametros, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JsonException) {
            return $this->erro('assinatura_eletronica.erro.dados_invalidos', [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, int|string> $parametros */
    private function erro(string $chave, array $parametros, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave, $parametros)], $status);
    }

    /**
     * O multipart do "Salvar rascunho": `dados` (JSON), `arquivo` (PDF) e `logo` (imagem), os dois opcionais.
     *
     * @throws AcaoRecusada
     * @throws JsonException
     */
    private function entradaDoRascunho(Request $request): EntradaDoRascunho
    {
        try {
            $dados = json_decode((string) $request->request->get('dados', ''), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException('O campo dados não é JSON.', 0, $e);
        }

        $arquivo = $request->files->get('arquivo');
        $logo = $request->files->get('logo');

        return new EntradaDoRascunho(
            DadosDoRascunho::deJson($dados),
            $arquivo instanceof UploadedFile ? $arquivo : null,
            $logo instanceof UploadedFile ? $logo : null,
            $request->request->getBoolean('envio'),
        );
    }
}
