<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\ModeloNaoEncontrado;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\ExcluirModeloUseCase;
use App\AssinaturaEletronica\UseCase\ListarModelosUseCase;
use App\AssinaturaEletronica\UseCase\SalvarModeloUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O JSON dos modelos do escritório (contrato da etapa 4, seção 4): listar, criar, salvar e excluir. POST com CSRF no
 * cabeçalho X-CSRF-Token (injetado pelo base.html.twig). Sem o módulo: 403; modelo de outro escritório: 404; dado
 * fora do contrato: 422 com `{erro}`.
 */
final class ModelosDaAssinaturaController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinatura/modelos', name: 'assinatura_eletronica_modelos', methods: ['GET'])]
    public function modelos(ListarModelosUseCase $listar): JsonResponse
    {
        return $this->comAcesso(null, static fn (User $eu, Tenant $tenant): array => $listar->executar($tenant));
    }

    #[Route('/assinatura/modelos', name: 'assinatura_eletronica_modelo_criar', methods: ['POST'])]
    public function criar(Request $request, SalvarModeloUseCase $salvar): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => [
            'id' => $salvar->executar($eu, $tenant, null, DadosDoModelo::deJson($request->toArray())),
        ], Response::HTTP_CREATED);
    }

    #[Route('/assinatura/modelos/{id}', name: 'assinatura_eletronica_modelo_salvar', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function salvar(int $id, Request $request, SalvarModeloUseCase $salvar): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => [
            'id' => $salvar->executar($eu, $tenant, $id, DadosDoModelo::deJson($request->toArray())),
        ]);
    }

    #[Route('/assinatura/modelos/{id}/excluir', name: 'assinatura_eletronica_modelo_excluir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function excluir(int $id, Request $request, ExcluirModeloUseCase $excluir): JsonResponse
    {
        return $this->comAcesso($request, static function (User $eu, Tenant $tenant) use ($id, $excluir): array {
            $excluir->executar($tenant, $id);

            return ['ok' => true];
        });
    }

    /**
     * Porta comum: módulo, CSRF (só nos POST) e as recusas como JSON.
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
        } catch (ModeloNaoEncontrado) {
            return $this->erro('assinatura_eletronica.modelo.erro.nao_encontrado', [], Response::HTTP_NOT_FOUND);
        } catch (AcaoRecusada $e) {
            return $this->erro($e->getMessage(), $e->parametros, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (JsonException) {
            return $this->erro('assinatura_eletronica.modelo.erro.dados_invalidos', [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /** @param array<string, int|string> $parametros */
    private function erro(string $chave, array $parametros, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave, $parametros)], $status);
    }
}
