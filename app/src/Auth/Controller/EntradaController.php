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
     * Primeira palavra do nome completo; sem nome, o começo do e-mail como no desenho
     * (`ana.souza@...` vira `Ana`).
     */
    private function primeiroNome(User $user): string
    {
        $partes = preg_split('/\s+/', trim((string) $user->getFullName()), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // "Dra. Ana Souza" cumprimenta a Ana, não a "Dra.".
        while (count($partes) > 1 && preg_match('/^(dr|dra|sr|sra)\.?$/iu', $partes[0]) === 1) {
            array_shift($partes);
        }
        $local = explode('@', (string) $user->getEmail())[0];
        $primeiro = $partes[0] ?? (preg_split('/[._-]/', $local) ?: [''])[0];

        // Nome gravado em maiúsculas ("SAMUEL FREITAS") cumprimenta o Samuel.
        return mb_strtoupper(mb_substr($primeiro, 0, 1)) . mb_strtolower(mb_substr($primeiro, 1));
    }
}
