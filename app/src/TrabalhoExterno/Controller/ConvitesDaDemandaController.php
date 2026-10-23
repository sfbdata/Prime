<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Controller;

use App\Shared\Trait\ValidaCsrfAjaxTrait;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FabricaDeContexto;
use App\TrabalhoExterno\UseCase\ConvidarProfissionalUseCase;
use App\TrabalhoExterno\UseCase\DetalharDemandaUseCase;
use App\TrabalhoExterno\UseCase\ReenviarConviteUseCase;
use App\TrabalhoExterno\UseCase\RevogarConviteUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** "PROFISSIONAIS CONVIDADOS" da aba Execução (F3): convidar, reenviar e revogar. Mesmas guardas das demais escritas. */
#[Route('/trabalho-externo/api/demandas/{codigo}/convites', requirements: ['codigo' => 'TE-\d{1,9}'])]
final class ConvitesDaDemandaController extends AbstractController
{
    use RespondeJsonTrait;
    use ValidaCsrfAjaxTrait;

    public function __construct(
        private readonly FabricaDeContexto $contextos,
        private readonly DetalharDemandaUseCase $detalhar,
    ) {
    }

    #[Route('', name: 'trabalho_externo_convidar', methods: ['POST'])]
    public function convidar(Request $request, string $codigo, ConvidarProfissionalUseCase $convidar): JsonResponse
    {
        return $this->escrever($request, $codigo, fn (Contexto $ctx) => $convidar->executar($ctx, $codigo, $this->corpo($request)));
    }

    #[Route('/{convite}/reenviar', name: 'trabalho_externo_convite_reenviar', methods: ['POST'], requirements: ['convite' => '\d+'])]
    public function reenviar(Request $request, string $codigo, int $convite, ReenviarConviteUseCase $reenviar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $reenviar->executar($ctx, $codigo, $convite));
    }

    #[Route('/{convite}/revogar', name: 'trabalho_externo_convite_revogar', methods: ['POST'], requirements: ['convite' => '\d+'])]
    public function revogar(Request $request, string $codigo, int $convite, RevogarConviteUseCase $revogar): JsonResponse
    {
        return $this->escrever($request, $codigo, static fn (Contexto $ctx) => $revogar->executar($ctx, $codigo, $convite));
    }

    /** @param callable(Contexto): mixed $acao */
    private function escrever(Request $request, string $codigo, callable $acao): JsonResponse
    {
        $ctx = $this->contextoOuNulo();
        if ($ctx === null) {
            return $this->semAcesso();
        }
        $this->validarCsrfAjax($request);

        return $this->responder(function () use ($ctx, $codigo, $acao): array {
            $acao($ctx);

            return ['demanda' => $this->detalhar->executar($ctx, $codigo)];
        });
    }
}
