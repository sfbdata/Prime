<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Service\DestinoPosLogin;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Entity\Auth\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Véu "Abrindo o seu escritório digital…" entre o login e o destino (spec login-desenho-10,
 * comportamento 2). O `?para=` vem da barra de endereço, então é revalidado aqui: o que não
 * passa no `DestinoPosLogin` vira o Expediente.
 *
 * A rota fica sob `^/login` (PUBLIC_ACCESS no security.yaml); por isso a exigência de usuário
 * logado é feita aqui mesmo.
 */
final class EntradaController extends AbstractController
{
    public function __construct(
        private readonly DestinoPosLogin $destinoPosLogin,
        private readonly TenantContext $tenantContext,
        private readonly UserTenantRepository $userTenantRepository,
    ) {
    }

    #[Route('/login/entrando', name: 'app_login_entrando', methods: ['GET'])]
    public function entrando(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        $para = $request->query->get('para');
        $destino = $this->destinoPosLogin->validar(is_string($para) ? $para : null, $request)
            ?? $this->generateUrl('expediente_index');
        // Sem escritório na sessão (vários vínculos, ou o "lembrar-me", que não passa pelo autenticador) e com
        // vínculo ativo: a seleção vem antes de qualquer tela do escritório.
        if (!$this->tenantContext->hasCurrentTenant() && $this->userTenantRepository->findActiveByUser($user) !== []) {
            $destino = $this->generateUrl('tenant_selecionar');
        }

        return $this->render('security/entrando.html.twig', [
            'destino' => $destino,
            'nome' => $this->primeiroNome($user),
        ]);
    }

    /**
     * Como no desenho 10 (VS 25.10.26): o começo do e-mail, antes do `@` e do primeiro `.`, `_` ou `-`, com a inicial
     * maiúscula (`ana.souza@...` vira `Ana`). O nome completo não entra na saudação.
     */
    private function primeiroNome(User $user): string
    {
        $local = explode('@', (string) $user->getEmail())[0];
        $primeiro = (preg_split('/[._-]/', $local) ?: [''])[0];

        return mb_strtoupper(mb_substr($primeiro, 0, 1)) . mb_substr($primeiro, 1);
    }
}
