<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\UseCase\MontarRelatorioDeContribuicoesUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cartão "Contribuições" do Painel do Master (desenho 08, L253: chave `rel` da Integração bancária). Devolve só
 * o parcial: o painel (`master/painel.html.twig`) o inclui com `render(path('contribua_relatorio_master'))`,
 * numa linha, sem o domínio Master conhecer o Contribua. Só o Master: dado de todas as contas.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class RelatorioDeContribuicoesController extends AbstractController
{
    #[Route('/contribua/relatorio-do-master', name: 'contribua_relatorio_master', methods: ['GET'])]
    public function __invoke(MontarRelatorioDeContribuicoesUseCase $useCase): Response
    {
        return $this->render('contribua/_relatorio_master.html.twig', ['r' => $useCase->executar()]);
    }
}
