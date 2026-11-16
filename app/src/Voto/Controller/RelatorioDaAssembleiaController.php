<?php

declare(strict_types=1);

namespace App\Voto\Controller;

use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\AvisosDoVoto;
use App\Voto\Service\GuardaDaEquipeDoVoto;
use App\Voto\Service\ImagemDoResumoDoResultado;
use App\Voto\Service\PdfDoResumoDoResultado;
use App\Voto\UseCase\GerarResumoDoResultadoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Resumos para divulgar da janela "Relatório" (desenho 18): imagem PNG e PDF, gerados na hora e devolvidos, sem gravar
 * arquivo. Só pauta encerrada e apurada, sem dado pessoal (C12). O relatório completo é a ata (voto_assembleia_ata).
 * Mesma guarda dos vizinhos: sem liberação 403; outro escritório e rascunho 404.
 */
#[Route('/voto/assembleias/{id}/relatorio', requirements: ['id' => '\d+'])]
final class RelatorioDaAssembleiaController extends AbstractController
{
    public function __construct(
        private readonly GuardaDaEquipeDoVoto $guarda,
        private readonly GerarResumoDoResultadoUseCase $gerar,
        private readonly ImagemDoResumoDoResultado $imagem,
        private readonly PdfDoResumoDoResultado $pdf,
        private readonly AvisosDoVoto $avisos,
    ) {
    }

    #[Route('/resumo.png', name: 'voto_assembleia_relatorio_imagem', methods: ['GET'])]
    public function baixarImagem(int $id): Response
    {
        return $this->resumo($id, fn (ResumoDoResultado $r): Response => $this->arquivo($this->imagem->gerar($r), 'image/png', 'resultado-assembleia-' . $id . '.png'));
    }

    #[Route('/resumo.pdf', name: 'voto_assembleia_relatorio_pdf', methods: ['GET'])]
    public function baixarPdf(int $id): Response
    {
        return $this->resumo($id, fn (ResumoDoResultado $r): Response => $this->arquivo($this->pdf->gerar($r), 'application/pdf', 'resultado-assembleia-' . $id . '.pdf'));
    }

    /** @param callable(ResumoDoResultado): Response $montar */
    private function resumo(int $id, callable $montar): Response
    {
        $tenant = $this->guarda->exigir();
        try {
            return $montar($this->gerar->executar($tenant, $id));
        } catch (AssembleiaNaoEncontradaException) {
            throw $this->createNotFoundException();
        } catch (OperacaoInvalidaNaAssembleiaException $e) {
            $this->avisos->erro($e->getMessage());

            return $this->redirectToRoute('voto_index', ['aba' => 'ges', '_fragment' => 'assembleia-' . $id]);
        }
    }

    private function arquivo(string $bytes, string $tipo, string $nome): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => $tipo,
            'Content-Disposition' => 'attachment; filename="' . $nome . '"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
