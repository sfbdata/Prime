<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Voto\DTO\EscritorioNaLiberacaoDoVotoOutput;
use App\Voto\Exception\EscritorioNaoEncontradoException;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use App\Voto\UseCase\ObterLiberacoesDoVotoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Voto" do Painel do Master (spec docs/specs/bluejus-voto.md, D5 e D7, fase A1b; nenhum desenho tem esta tela,
 * então vale o molde da aba da I.A.). Mora no domínio Master, como o IaDoMasterController: a rota e a guarda são do
 * painel; as regras moram nos UseCases do domínio Voto, que conferem o Master de novo.
 *
 * JSON. O POST leva o token CSRF `master_voto` no header `X-CSRF-Token` e responde o mesmo corpo do GET, já com o
 * estado novo; recusa em 422 com `{erro}` (403 no CSRF, 404 no escritório que não existe ou não está ativo).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class VotoDoMasterController extends AbstractController
{
    public const TOKEN = 'master_voto';

    public function __construct(
        private readonly ObterLiberacoesDoVotoUseCase $liberacoes,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/voto', name: 'master_voto_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** Uma linha da grade. Corpo `{liberado: bool}`; outro tipo é 422. */
    #[Route('/master/voto/escritorios/{tenantId}', name: 'master_voto_escritorio', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function escritorio(int $tenantId, Request $request, AlterarLiberacaoDoVotoUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->recusa('master.voto.recusa.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $liberado = self::corpo($request)['liberado'] ?? null;
        if (!is_bool($liberado)) {
            return $this->recusa('master.voto.recusa.informe_liberado', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($tenantId, $liberado, $this->master());
        } catch (EscritorioNaoEncontradoException) {
            return $this->recusa('master.voto.recusa.escritorio_nao_encontrado', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->estado());
    }

    /** @return array{escritorios: list<array{tenantId: int, nome: string, liberado: bool}>} */
    private function estado(): array
    {
        return [
            'escritorios' => array_map(static fn (EscritorioNaLiberacaoDoVotoOutput $e): array => [
                'tenantId' => $e->tenantId,
                'nome' => $e->nome,
                'liberado' => $e->liberado,
            ], $this->liberacoes->executar($this->master())),
        ];
    }

    private function recusa(string $chave, int $status): JsonResponse
    {
        return $this->json(['erro' => $this->tradutor->trans($chave)], $status);
    }

    private function master(): User
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $usuario;
    }

    /** @return array<mixed> */
    private static function corpo(Request $request): array
    {
        try {
            $dados = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($dados) ? $dados : [];
    }
}
