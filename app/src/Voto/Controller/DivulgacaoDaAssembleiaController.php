<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\QrCodeDaAssembleia;
use App\Voto\Service\CartazDaAssembleia;
use App\Voto\UseCase\MontarDivulgacaoDaAssembleiaUseCase;
use App\Voto\UseCase\ObterAssembleiaUseCase;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/voto/assembleias/{id}/divulgacao', requirements: ['id' => '\d+'])]
final class DivulgacaoDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly MontarDivulgacaoDaAssembleiaUseCase $montar,
        private readonly ObterAssembleiaUseCase $obter,
        private readonly QrCodeDaAssembleia $qr,
        private readonly CartazDaAssembleia $cartaz,
    ) {
    }

    #[Route('', name: 'voto_assembleia_divulgacao', methods: ['GET'])]
    public function mostrar(int $id): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $tela = $this->montar->executar($tenant, $id);
            $assembleia = $this->obter->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }

        return $this->render('voto/assembleia/divulgacao.html.twig', ['tela' => $tela, 'assembleia' => $assembleia, 'sub' => 'unidades']);
    }

    #[Route('/arquivo/{formato}', name: 'voto_assembleia_divulgacao_arquivo', requirements: ['formato' => 'png|svg|cartaz'], methods: ['GET'])]
    public function arquivo(int $id, string $formato): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            $tela = $this->montar->executar($tenant, $id);
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        }
        $bytes = match ($formato) {
            'svg' => $this->qr->svg($tela->link),
            'cartaz' => $this->cartaz->gerar($tela),
            default => $this->qr->png($tela->link),
        };
        $extensao = $formato === 'svg' ? 'svg' : 'png';

        return new Response($bytes, 200, [
            'Content-Type' => $formato === 'svg' ? 'image/svg+xml' : 'image/png',
            'Content-Disposition' => 'inline; filename="' . ($formato === 'cartaz' ? 'cartaz-' : '') . 'qrcode-assembleia-' . $id . '.' . $extensao . '"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
