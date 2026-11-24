<?php

declare(strict_types=1);

namespace App\Profile\Controller;

use App\Entity\Auth\User;
use App\Master\Exception\ConvenioRecusado;
use App\Master\UseCase\AplicarCodigoDeConvenioUseCase;
use App\Service\Tenant\TenantContext;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Tenho um código de convênio" do Meu Perfil (spec docs/specs/convenios.md, §5.3 e §9, PR 5). Rota do núcleo, com o
 * escritório da sessão (nunca do pedido) e a checagem de primário no UseCase: secundário recebe 403. Cinco tentativas
 * por hora por escritório (RateLimiter `convenio_codigo`), contadas depois do CSRF. Volta ao Meu Perfil com o aviso.
 */
final class ConvenioDoPerfilController extends AbstractController
{
    public const CSRF = 'profile_convenio_codigo';

    public function __construct(
        private readonly AplicarCodigoDeConvenioUseCase $aplicar,
        private readonly TenantContext $tenantContext,
        private readonly RateLimiterFactoryInterface $convenioCodigoLimiter,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/perfil/convenio/codigo', name: 'app_profile_convenio_codigo', methods: ['POST'])]
    public function aplicar(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token inválido.');
        }
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$user instanceof User || $tenant === null) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->aplicar->exigirPrimario($user, $tenant);
        } catch (OperacaoDePrimarioRecusada $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
        if (!$this->convenioCodigoLimiter->create('escritorio-' . $tenant->getId())->consume()->isAccepted()) {
            $this->addFlash('error', $this->tradutor->trans('perfil.convenio.muitas_tentativas'));

            return $this->redirectToRoute('app_profile');
        }

        try {
            $convenio = $this->aplicar->executar($user, $tenant, (string) $request->request->get('codigo'));
            $this->addFlash('success', $this->tradutor->trans('perfil.convenio.aplicado', ['nome' => $convenio->getNome()]));
        } catch (OperacaoDePrimarioRecusada $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        } catch (ConvenioRecusado $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }
}
