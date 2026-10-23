<?php

declare(strict_types=1);

namespace App\Shared\Scheduler\Controller;

use App\Entity\Auth\User;
use App\Shared\Scheduler\QuemVeAvisoDoAgendador;
use App\Shared\Scheduler\UseCase\DispensarFalhasAgendadasUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O "Dispensar" do aviso de falha das tarefas agendadas (spec scheduler-tarefas-agendadas, "Aviso de falha").
 * Só POST com CSRF; 403 para quem não vê o aviso. Volta para a página de onde veio, só se for um caminho
 * local (nada de redirecionamento aberto); senão, para o início.
 */
final class FalhaAgendadaController extends AbstractController
{
    public const CSRF = 'dispensar_falhas_agendadas';

    public function __construct(
        private readonly QuemVeAvisoDoAgendador $quemVe,
        private readonly DispensarFalhasAgendadasUseCase $dispensar,
    ) {
    }

    #[Route('/agendador/falhas/dispensar', name: 'agendador_falhas_dispensar', methods: ['POST'])]
    public function dispensar(Request $request): Response
    {
        $usuario = $this->getUser();
        if (!$usuario instanceof User || !$this->quemVe->podeVer($usuario)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $this->dispensar->executar($usuario);

        return $this->redirect($this->destinoSeguro((string) $request->request->get('voltar')));
    }

    /** Só caminho local: começa com uma barra, sem "//", sem barra invertida nem caractere de controle. */
    private function destinoSeguro(string $voltar): string
    {
        foreach ([$voltar, rawurldecode($voltar)] as $forma) {
            if (!str_starts_with($forma, '/') || str_starts_with($forma, '//')
                || str_contains($forma, '\\') || preg_match('/[\x00-\x1F\x7F]/', $forma) === 1) {
                return $this->generateUrl('homepage');
            }
        }

        return $voltar;
    }
}
