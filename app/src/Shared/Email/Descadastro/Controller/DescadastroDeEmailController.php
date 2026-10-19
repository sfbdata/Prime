<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Controller;

use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O link "Não quero mais receber estes avisos" dos e-mails (spec push-avisar-quando-chegar D3).
 *
 * Rota PÚBLICA (PUBLIC_ACCESS no security.yaml; fora dos portões de termo e de escritório): quem
 * clica não precisa estar logado. A guarda é a assinatura da URL: alterada ou ausente, 404. Grava
 * o descadastro (idempotente: o segundo clique só mostra a confirmação de novo) e mostra uma página
 * simples. GET é o clique no link; POST é o "one-click" dos provedores de e-mail (RFC 8058), pelo
 * cabeçalho List-Unsubscribe-Post.
 */
final class DescadastroDeEmailController extends AbstractController
{
    public function __construct(
        private readonly LinkDeDescadastro $link,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly TenantRepository $tenants,
    ) {
    }

    #[Route('/email/descadastro', name: LinkDeDescadastro::ROTA, methods: ['GET', 'POST'])]
    public function descadastrar(Request $request): Response
    {
        $pedido = $this->link->ler($request);
        $tenant = $pedido === null ? null : $this->tenants->find($pedido->tenantId);
        if ($pedido === null || !$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Link de descadastro inválido.');
        }

        try {
            $this->descadastro->descadastrar($tenant, $pedido->email, $pedido->assunto, $request->isMethod('POST') ? 'one_click' : 'link_do_email');
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Link de descadastro inválido.');
        }

        $resposta = $this->render('email_descadastro/confirmado.html.twig', ['escritorio' => $tenant->getName()]);
        $resposta->headers->set('X-Robots-Tag', 'noindex');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
