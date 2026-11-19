<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\UseCase\ConsultarPastasRecebidasUseCase;
use App\Pasta\UseCase\ResponderPastaRecebidaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A caixa "Recebidas" da Lista do Expediente (spec acesso-restrito-e-compartilhar §4.5, fatia 8; desenho L9 e L10). Só
 * JSON. É da pessoa logada, não do escritório da sessão: os convites vêm de outras contas e cada consulta olha só o
 * destino. Por isso não há pasta na rota (a pasta é de outro escritório; abri-la é da fatia 9).
 *
 * O GET devolve o token dos POSTs (`pastas_recebidas`). Nome `expediente_*`: o acesso restrito alcança (é como o
 * parceiro recebe pasta de outra conta).
 */
#[Route('/expediente/recebidas')]
final class PastasRecebidasController extends AbstractController
{
    private const TOKEN = 'pastas_recebidas';

    public function __construct(
        private readonly ConsultarPastasRecebidasUseCase $consultar,
        private readonly ResponderPastaRecebidaUseCase $responder,
    ) {
    }

    #[Route('', name: 'expediente_recebidas', methods: ['GET'])]
    public function ver(): JsonResponse
    {
        $saida = $this->consultar->executar($this->pessoa());

        return $this->json($saida->paraJson() + ['token' => $this->container->get('security.csrf.token_manager')->getToken(self::TOKEN)->getValue()]);
    }

    #[Route('/{compartilhamentoId}/{acao}', name: 'expediente_recebida_responder', requirements: ['compartilhamentoId' => '\d+', 'acao' => 'aceitar|recusar|deixar'], methods: ['POST'])]
    public function responder(int $compartilhamentoId, string $acao, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $convite = $this->responder->executar($compartilhamentoId, $acao, $this->pessoa());
        } catch (CompartilhamentoRecusadoException $e) {
            return $this->json(['erro' => $e->getMessage()], $e->ehDaPessoa() ? Response::HTTP_NOT_FOUND : Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ok' => true, 'nup' => (string) $convite->getPasta()->getNup()]);
    }

    private function pessoa(): User
    {
        $pessoa = $this->getUser();
        if (!$pessoa instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $pessoa;
    }
}
