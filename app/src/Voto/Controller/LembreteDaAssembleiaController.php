<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\QuemNaoVotouOutput;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\UseCase\ListarQuemNaoVotouUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Lembrar quem não votou" (desenho de 14 e 15/11): a janela (fragmento HTML, aberta por public/js/voto-lembrete.js)
 * e o CSV de quem não votou. Mesma guarda da equipe e mesmo escopo de escritório dos demais controllers da assembleia:
 * de outro escritório dá 404; sem liberação do Voto, 403. O envio é do gestor, pelo WhatsApp dele.
 */
#[Route('/voto/assembleias/{id}/lembrete', requirements: ['id' => '\d+'])]
final class LembreteDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly ListarQuemNaoVotouUseCase $listar,
    ) {
    }

    #[Route('', name: 'voto_assembleia_lembrete', methods: ['GET'])]
    public function janela(int $id): Response
    {
        $tela = $this->montar($id);

        return $this->render('voto/assembleia/_janela_lembrar.html.twig', ['tela' => $tela]);
    }

    #[Route('/lista.csv', name: 'voto_assembleia_lembrete_csv', methods: ['GET'])]
    public function csv(int $id): Response
    {
        $tela = $this->montar($id);

        return new Response($tela->csv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lembrete-' . $id . '.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function montar(int $id): QuemNaoVotouOutput
    {
        $tenant = $this->guarda->exigir();
        try {
            return $this->listar->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
    }
}
