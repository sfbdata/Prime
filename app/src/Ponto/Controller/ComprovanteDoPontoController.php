<?php

declare(strict_types=1);

namespace App\Ponto\Controller;

use App\Entity\Auth\User;
use App\Ponto\UseCase\GerarImagemDoComprovanteUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Download do comprovante da batida em imagem (E15, P49): o botão "Baixar" da aba Comprovantes. Exige login, o
 * escritório escolhido e o módulo Ponto; o comprovante tem de ser da própria pessoa neste escritório. Qualquer outro
 * caso é 404, para não contar que o comprovante existe.
 *
 * A imagem é gerada na hora a partir dos dados selados (não é arquivo guardado), então sai numa resposta comum.
 * Cada pessoa gera no máximo 10 imagens por minuto (`ponto_comprovante_imagem`): o GD custa CPU e memória.
 */
final class ComprovanteDoPontoController extends AbstractController
{
    public const MUITOS_PEDIDOS = 'Muitos pedidos de comprovante em sequência. Aguarde um minuto e tente de novo.';

    public function __construct(
        private readonly GerarImagemDoComprovanteUseCase $gerar,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly RateLimiterFactoryInterface $pontoComprovanteImagemLimiter,
    ) {
    }

    #[Route('/ponto/comprovantes/{id}/baixar', name: 'ponto_comprovante_baixar', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function baixar(int $id): Response
    {
        $pessoa = $this->getUser();
        $escritorio = $this->tenantContext->getCurrentTenant();
        if (!$pessoa instanceof User || $escritorio === null || !$this->permissionChecker->canAccessModule($pessoa, $escritorio, 'ponto')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Ponto Eletrônico.');
        }

        if (!$this->pontoComprovanteImagemLimiter->create((string) $pessoa->getId())->consume()->isAccepted()) {
            return new Response(self::MUITOS_PEDIDOS, Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $imagem = $this->gerar->executar($id, $pessoa, $escritorio);
        if ($imagem === null) {
            throw $this->createNotFoundException('Comprovante não encontrado.');
        }

        return new Response($imagem['jpeg'], Response::HTTP_OK, [
            'Content-Type'           => 'image/jpeg',
            'Content-Disposition'    => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $imagem['nome']),
            'Cache-Control'          => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
