<?php

declare(strict_types=1);

namespace App\Inteligencia\Controller;

use App\Entity\Auth\User;
use App\Inteligencia\DTO\ConfiguracaoDeInteligenciaInput;
use App\Inteligencia\Form\ConfiguracaoDeInteligenciaType;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\UseCase\AtualizarConfiguracaoDeInteligenciaUseCase;
use App\Inteligencia\UseCase\ConsultarConfiguracaoDeInteligenciaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * /admin/inteligencia — o admin do escritório liga/desliga a BlueJus IA, define cotas e o
 * mascaramento. Gate: `admin.inteligencia.manage`. Uma action só: GET mostra, POST grava e
 * redireciona (padrão da skill `criar-form`).
 */
final class ConfiguracaoController extends AbstractController
{
    public const PERMISSAO = 'admin.inteligencia.manage';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly ConsultarConfiguracaoDeInteligenciaUseCase $consultar,
        private readonly AtualizarConfiguracaoDeInteligenciaUseCase $atualizar,
    ) {
    }

    #[Route('/admin/inteligencia', name: 'inteligencia_admin_config', methods: ['GET', 'POST'])]
    public function configurar(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || !$this->permissionChecker->canAdminister($user, $tenant, self::PERMISSAO)) {
            throw $this->createAccessDeniedException('Sem permissão para configurar a BlueJus IA.');
        }

        $input = ConfiguracaoDeInteligenciaInput::fromEntity($this->configuracoes->findDoTenant($tenant));
        $form = $this->createForm(ConfiguracaoDeInteligenciaType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->atualizar->executar($input, $user, $tenant);
            $this->addFlash('success', 'Configuração da BlueJus IA salva.');

            return $this->redirectToRoute('inteligencia_admin_config');
        }

        return $this->render('inteligencia/admin/configuracao.html.twig', [
            'form' => $form,
            'configuracao' => $this->consultar->executar($tenant),
        ]);
    }
}
