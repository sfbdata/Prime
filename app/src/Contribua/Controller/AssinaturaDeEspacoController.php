<?php

declare(strict_types=1);

namespace App\Contribua\Controller;

use App\Contribua\Service\SolicitanteDaSessao;
use App\Contribua\UseCase\AssinarComTokenUseCase;
use App\Contribua\UseCase\CancelarAssinaturaUseCase;
use App\Contribua\UseCase\ConfigurarPagamentoUseCase;
use App\Contribua\UseCase\ConfirmarPagamentoUseCase;
use App\Contribua\UseCase\IniciarAssinaturaUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Cartão (C3): o checkout "Pagamento seguro", o cancelamento (Meu Perfil) e o modal "Pagamento por cartão ·
 * só Master" do desenho 08. Respostas no formato que o JS da página lê: `{ok, ...}` ou `{ok: false, erro}`.
 * Nenhuma rota recebe número, validade ou CVV de cartão: só o token gerado no navegador.
 */
#[Route('/contribua')]
final class AssinaturaDeEspacoController extends AbstractController
{
    private const CSRF = 'contribua';

    public function __construct(
        private readonly SolicitanteDaSessao $sessao,
    ) {
    }

    #[Route('/assinatura', name: 'contribua_assinatura_iniciar', methods: ['POST'])]
    public function iniciar(Request $request, IniciarAssinaturaUseCase $iniciar): JsonResponse
    {
        return $this->acao($request, fn (): array => $iniciar->executar(
            $this->sessao->atual(),
            (string) $request->request->get('plano', ''),
            (string) $request->request->get('documento', ''),
            $request->getClientIp(),
        )->paraJson());
    }

    #[Route('/assinatura/{id}/token', name: 'contribua_assinatura_token', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function token(int $id, Request $request, AssinarComTokenUseCase $assinar): JsonResponse
    {
        return $this->acao($request, fn (): array => $assinar->executar(
            $this->sessao->atual(),
            $id,
            (string) $request->request->get('token', ''),
            (string) $request->request->get('documento', ''),
            $request->getClientIp(),
        ));
    }

    #[Route('/assinatura/cancelar', name: 'contribua_assinatura_cancelar', methods: ['POST'])]
    public function cancelar(Request $request, CancelarAssinaturaUseCase $cancelar): JsonResponse
    {
        return $this->acao($request, fn (): array => ['mensagem' => $cancelar->executar($this->sessao->atual())]);
    }

    #[Route('/pagamento', name: 'contribua_pagamento_configurar', methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function configurar(Request $request, ConfigurarPagamentoUseCase $configurar): JsonResponse
    {
        return $this->acao($request, function () use ($request, $configurar): array {
            $links = $request->request->all('links');
            $configurar->executar(
                (string) $request->request->get('gateway', ''),
                (string) $request->request->get('chavePublica', ''),
                array_map(static fn (mixed $link): string => \is_string($link) ? $link : '', $links),
                (int) $request->request->get('carencia', 7),
            );

            return ['mensagem' => 'Pagamento por cartão salvo.'];
        });
    }

    #[Route('/pagamento/{id}/confirmar', name: 'contribua_pagamento_confirmar', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_SUPER_ADMIN')]
    public function confirmar(int $id, Request $request, ConfirmarPagamentoUseCase $confirmar): JsonResponse
    {
        return $this->acao($request, function () use ($id, $confirmar): array {
            $confirmar->executar($this->sessao->usuario(), $id);

            return ['mensagem' => 'Pagamento confirmado e espaço liberado.'];
        });
    }

    /** @param callable(): array<string, mixed> $acao */
    private function acao(Request $request, callable $acao): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['ok' => false, 'erro' => 'Sessão expirada. Recarregue a página.'], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->json(['ok' => true, ...$acao()]);
        } catch (\DomainException $recusa) {
            return $this->json(['ok' => false, 'erro' => $recusa->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
