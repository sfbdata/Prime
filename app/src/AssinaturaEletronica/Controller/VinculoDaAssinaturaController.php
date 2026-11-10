<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Controller;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\PastaRecusadaNoVinculo;
use App\AssinaturaEletronica\Service\AcessoAAssinatura;
use App\AssinaturaEletronica\UseCase\BuscarPastasParaVincularUseCase;
use App\AssinaturaEletronica\UseCase\VincularAPastaUseCase;
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
 * "Vincular à pasta de Expediente" da tela Assinatura (spec assinatura-documentos-etapa-3, E3-10 e E3-11): a busca de
 * pastas da janela e o vínculo. POST com CSRF no cabeçalho X-CSRF-Token. Sem o módulo: 403; documento de outro
 * escritório: 404; pasta que não existe, é de outro escritório ou a pessoa não vê: 404; vê e não edita: 403; pasta na
 * lápide ou documento fora de aguardando/concluído, na Lixeira ou já vinculado: 422 com `{erro}`.
 */
final class VinculoDaAssinaturaController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly AcessoAAssinatura $acesso,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/assinatura/pastas', name: 'assinatura_eletronica_pastas', methods: ['GET'])]
    public function pastas(Request $request, BuscarPastasParaVincularUseCase $buscar): JsonResponse
    {
        return $this->comAcesso(null, static fn (User $eu, Tenant $tenant): array => $buscar->executar($eu, $tenant, (string) $request->query->get('q', '')));
    }

    #[Route('/assinatura/documentos/{id}/vincular', name: 'assinatura_eletronica_vincular', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function vincular(int $id, Request $request, VincularAPastaUseCase $vincular): JsonResponse
    {
        return $this->comAcesso($request, static fn (User $eu, Tenant $tenant): array => ['ok' => true] + $vincular->executar($eu, $tenant, $id, self::pastaDoPedido($request)));
    }

    /**
     * @throws AcaoRecusada
     * @throws JsonException
     */
    private static function pastaDoPedido(Request $request): int
    {
        $pasta = $request->toArray()['pasta'] ?? null;
        if (!\is_int($pasta) || $pasta < 1) {
            throw new AcaoRecusada('assinatura_eletronica.erro.vinculo_sem_pasta');
        }

        return $pasta;
    }

    private function comAcesso(?Request $post, \Closure $acao): JsonResponse
    {
        $quem = $this->acesso->quemPode();
        if ($quem === null) {
            return $this->erro('assinatura_eletronica.erro.sem_permissao', [], Response::HTTP_FORBIDDEN);
        }

        if ($post !== null) {
            $this->validarCsrfAjax($post);
        }

        try {
            return $this->json($acao(...$quem));
        } catch (DocumentoNaoEncontrado) {
            return $this->erro('assinatura_eletronica.erro.nao_encontrado', [], Response::HTTP_NOT_FOUND);
        } catch (PastaRecusadaNoVinculo $e) {
            return $this->erro($e->getMessage(), [], $e->status);
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
}
