<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Produto\DTO\EscritorioNasFerramentasOutput;
use App\Produto\Enum\Produto;
use App\Produto\Exception\EscritorioNaoEncontradoException;
use App\Produto\UseCase\AlterarFerramentaDoEscritorioPeloMasterUseCase;
use App\Produto\UseCase\ObterFerramentasDosEscritoriosUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aba "Ferramentas" do Painel do Master (spec docs/specs/multiproduto-contratacao-e-voto.md, §10; nenhum desenho tem esta
 * tela, então vale o molde da aba do Voto). Mora no domínio Master, como o VotoDoMasterController: a rota e a guarda são do
 * painel; as regras moram nos UseCases do domínio Produto, que conferem o Master de novo. A rota fala em "produtos"
 * (nome técnico); para a pessoa o termo é "Ferramentas", o mesmo do cartão do Meu Perfil.
 *
 * JSON. O POST leva o token CSRF `master_ferramentas` no header `X-CSRF-Token` e responde o mesmo corpo do GET, já com o
 * estado novo; recusa em `{erro}`: 403 no CSRF, 404 no escritório que não existe ou não está ativo, 422 no corpo inválido
 * (produto fora da lista ou `ligado` que não é booleano).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class FerramentasDoMasterController extends AbstractController
{
    public const TOKEN = 'master_ferramentas';

    public function __construct(
        private readonly ObterFerramentasDosEscritoriosUseCase $escritorios,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/master/produtos', name: 'master_produtos_dados', methods: ['GET'])]
    public function dados(): JsonResponse
    {
        return $this->json($this->estado());
    }

    /** Uma linha da grade. Corpo `{produto: "voto", ligado: bool}`; `completo` também vale. */
    #[Route('/master/produtos/escritorios/{tenantId}', name: 'master_produtos_escritorio', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function escritorio(int $tenantId, Request $request, AlterarFerramentaDoEscritorioPeloMasterUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->recusa('master.produtos.recusa.token_invalido', Response::HTTP_FORBIDDEN);
        }
        $corpo = self::corpo($request);
        $produto = is_string($corpo['produto'] ?? null) ? Produto::tryFrom($corpo['produto']) : null;
        if (null === $produto) {
            return $this->recusa('master.produtos.recusa.produto_invalido', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!is_bool($corpo['ligado'] ?? null)) {
            return $this->recusa('master.produtos.recusa.informe_ligado', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $alterar->executar($tenantId, $produto, $corpo['ligado'], $this->master());
        } catch (EscritorioNaoEncontradoException) {
            return $this->recusa('master.produtos.recusa.escritorio_nao_encontrado', Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->estado());
    }

    /** @return array{ferramentas: list<array{valor: string, rotulo: string}>, escritorios: list<array<string, mixed>>} */
    private function estado(): array
    {
        return [
            'ferramentas' => array_map(fn (Produto $p): array => ['valor' => $p->value, 'rotulo' => $this->rotulo($p)], Produto::ferramentas()),
            'escritorios' => array_map(fn (EscritorioNasFerramentasOutput $e): array => [
                'tenantId' => $e->tenantId,
                'nome' => $e->nome,
                'completo' => $e->completo,
                'ligadas' => array_map(static fn (Produto $p): string => $p->value, $e->ligadas),
                'travadas' => array_map(static fn (Produto $p): string => $p->value, $e->travadas),
            ], $this->escritorios->executar($this->master())),
        ];
    }

    private function rotulo(Produto $produto): string
    {
        return $this->tradutor->trans('master.produtos.nome.' . $produto->value);
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
