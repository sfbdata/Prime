<?php

declare(strict_types=1);

namespace App\Central\Controller;

use App\Central\DTO\DestinoDoEncaminhamentoOutput;
use App\Central\DTO\EncaminharItemInput;
use App\Central\Exception\ConversaCriadaAoMesmoTempoException;
use App\Central\Exception\ConversaNaoEncontradaException;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Exception\PedidoInvalidoNaCentralException;
use App\Central\Exception\SemAcessoACentralException;
use App\Central\UseCase\EncaminharItemDaPastaUseCase;
use App\Central\UseCase\ListarDestinosDoEncaminhamentoUseCase;
use App\Entity\Auth\User;
use App\Service\Tenant\TenantContext;
use App\Shared\Trait\ValidaCsrfAjaxTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Encaminhar via Chat I.A" (`docs/specs/encaminhar-via-chat-ia.md`): a lista de destinos e o despacho de um item da
 * pasta para a Central. Mesmo padrão do `CentralController`: a regra mora nos UseCases, aqui só HTTP. Escrita exige o
 * token CSRF `ajax` no cabeçalho `X-CSRF-Token`.
 */
#[Route('/central/encaminhar')]
final class EncaminhamentoController extends AbstractController
{
    use ValidaCsrfAjaxTrait;

    /** Maior valor de uma coluna `int` do PostgreSQL: o tipo dos ids. */
    private const MAIOR_ID = 2_147_483_647;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListarDestinosDoEncaminhamentoUseCase $listarDestinos,
        private readonly EncaminharItemDaPastaUseCase $encaminhar,
    ) {
    }

    #[Route('/destinos', name: 'central_encaminhar_destinos', methods: ['GET'])]
    public function destinos(Request $request): JsonResponse
    {
        $pastaId = self::idDaQuery($request, 'pasta');

        return $this->responder(fn () => ['destinos' => array_map(
            static fn (DestinoDoEncaminhamentoOutput $d): array => $d->toArray(),
            $this->listarDestinos->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $pastaId),
        )]);
    }

    #[Route('', name: 'central_encaminhar', methods: ['POST'])]
    public function encaminhar(Request $request): JsonResponse
    {
        $this->validarCsrfAjax($request);
        $corpo = $this->corpo($request);

        return $this->responder(fn () => $this->encaminhar->executar(
            $this->usuario(),
            $this->tenantContext->getCurrentTenant(),
            EncaminharItemInput::fromArray($corpo),
        )->toArray(), Response::HTTP_CREATED);
    }

    /** @param callable(): mixed $acao */
    private function responder(callable $acao, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            return $this->json($acao(), $status);
        } catch (SemAcessoACentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (ItemEncaminhadoNaoEncontradoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (ConversaNaoEncontradaException) {
            return $this->json(['erro' => 'Conversa não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (PedidoInvalidoNaCentralException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ConversaCriadaAoMesmoTempoException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    /** @return array<string, mixed> */
    private function corpo(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Id da query dentro do `int` do PostgreSQL; ausente ou torto vira 0, que nenhuma pasta tem (o UseCase dá 404). */
    private static function idDaQuery(Request $request, string $chave): int
    {
        $valor = $request->query->get($chave);
        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10 || (int) $valor > self::MAIOR_ID) {
            return 0;
        }

        return (int) $valor;
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
