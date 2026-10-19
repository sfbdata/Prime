<?php

declare(strict_types=1);

namespace App\Comercial\Controller;

use App\Comercial\DTO\DisparoOutput;
use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\Exception\AutomacaoRecusada;
use App\Comercial\UseCase\AlterarAutomacaoUseCase;
use App\Comercial\UseCase\ListarAutomacoesUseCase;
use App\Comercial\UseCase\SimularNovoCadastroUseCase;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * O bloco "Automações" da seção "Comercial e e-mails" do /master (desenho 05 L.262-268; spec
 * comercial-e-emails.md §3.5). Só o Master. JSON, com o token CSRF `master_comercial` no header `X-CSRF-Token`.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class ComercialDoMasterController extends AbstractController
{
    public const TOKEN = 'master_comercial';

    #[Route('/master/comercial/automacoes', name: 'master_comercial_automacoes', methods: ['GET'])]
    public function listar(ListarAutomacoesUseCase $listar): JsonResponse
    {
        $saida = $listar->executar();

        return $this->json([
            'automacoes' => array_map(self::automacaoEmJson(...), $saida['automacoes']),
            'ultimos' => array_map(static fn (DisparoOutput $d): array => (array) $d, $saida['ultimos']),
        ]);
    }

    #[Route('/master/comercial/automacoes/{chave}', name: 'master_comercial_automacao', requirements: ['chave' => '[a-z0-9]{2,10}'], methods: ['POST'])]
    public function alterar(string $chave, Request $request, AlterarAutomacaoUseCase $alterar): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }
        $corpo = json_decode($request->getContent(), true);
        if (!is_array($corpo) || !is_bool($corpo['ligada'] ?? null)) {
            return $this->json(['erro' => 'Informe se a automação fica ligada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $usuario = $this->getUser();
        try {
            $estado = $alterar->executar($chave, $corpo['ligada'], is_string($corpo['atraso'] ?? null) ? $corpo['atraso'] : null, $usuario instanceof User ? $usuario : throw new AutomacaoRecusada('Sessão inválida.'));
        } catch (AutomacaoRecusada $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(self::automacaoEmJson($estado));
    }

    #[Route('/master/comercial/simular-cadastro', name: 'master_comercial_simular', methods: ['POST'])]
    public function simular(Request $request, SimularNovoCadastroUseCase $simular): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['erro' => 'Token CSRF inválido. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(['mensagem' => $simular->executar()]);
    }

    /** @return array<string, mixed> */
    private static function automacaoEmJson(EstadoDaAutomacao $e): array
    {
        $a = $e->automacao;

        return [
            'chave' => $a->value, 'titulo' => $a->titulo(), 'descricao' => $a->descricao(), 'modelo' => $a->modelo(),
            'opcoes' => $a->opcoesDeAtraso(), 'atraso' => $e->atraso, 'ligada' => $e->ligada, 'obrigatoria' => $a->obrigatoria(),
        ];
    }
}
