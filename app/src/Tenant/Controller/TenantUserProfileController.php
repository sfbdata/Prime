<?php
declare(strict_types=1);
namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Profile\DTO\DadosPessoaisInput;
use App\Profile\Form\DadosPessoaisType;
use App\Profile\UseCase\AtualizarDadosPessoaisUseCase;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use App\Service\PermissionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/tenant')]
final class TenantUserProfileController extends AbstractController
{
    public function __construct(
        private readonly ObterOuCriarPerfilUseCase $obterOuCriarPerfil,
        private readonly AtualizarDadosPessoaisUseCase $atualizarDadosPessoais,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    #[Route('/{tenantId}/user/{id}/dados-pessoais', name: 'app_tenant_user_dados_pessoais', methods: ['POST'])]
    public function salvarDadosPessoais(
        int $tenantId,
        User $user,
        Request $request,
    ): Response {
        $currentUser = $this->getUser();

        if ($currentUser === null) {
            throw $this->createAccessDeniedException();
        }

        $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true);
        $isOwnTenant  = $currentUser->getTenant()?->getId() === $tenantId;

        if (!$isSuperAdmin && !($isOwnTenant && $this->permissionChecker->canAdminister($currentUser, 'admin.users.manage'))) {
            throw $this->createAccessDeniedException('Você não tem permissão para editar dados de usuário.');
        }

        $perfil = $this->obterOuCriarPerfil->executar($user);
        $input  = new DadosPessoaisInput();
        $form   = $this->createForm(DadosPessoaisType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->atualizarDadosPessoais->executar($perfil, $input);
            $this->addFlash('success', 'Dados pessoais atualizados com sucesso!');

            return $this->redirectToRoute('app_tenant_user_edit_role', [
                'tenantId' => $tenantId,
                'id'       => $user->getId(),
                'tab'      => 'dados-pessoais',
            ]);
        }

        foreach ($form->getErrors(true) as $error) {
            $this->addFlash('danger', $error->getMessage());
        }

        return $this->redirectToRoute('app_tenant_user_edit_role', [
            'tenantId' => $tenantId,
            'id'       => $user->getId(),
            'tab'      => 'dados-pessoais',
        ]);
    }
}
