<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Controller;

use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
use App\Shared\Email\Descadastro\PedidoDeDescadastro;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O link "Não quero mais receber estes avisos" dos e-mails (spec push-avisar-quando-chegar D3).
 *
 * Rota PÚBLICA (PUBLIC_ACCESS no security.yaml; fora dos portões de termo e de escritório): quem
 * clica não precisa estar logado. A guarda é a assinatura da URL: alterada ou ausente, 404.
 *
 *   - GET NÃO grava: mostra o pedido com o endereço mascarado e o botão "Confirmar". Antivírus de
 *     e-mail pré-abrem os links (GET) e descadastrariam sem ninguém pedir;
 *   - POST na MESMA URL assinada grava (idempotente) e mostra a confirmação. Serve ao botão e ao
 *     one-click dos provedores (RFC 8058, cabeçalho List-Unsubscribe-Post). Sem token CSRF: o
 *     provedor não teria como mandá-lo, e a assinatura do link já prova que o pedido veio do e-mail;
 *   - os chips de motivo (opcionais) gravam o texto da chave escolhida (lista fixa); chave fora dela é ignorada;
 *   - a opção "Não quero receber nenhum e-mail deste remetente" (desmarcada por padrão) grava
 *     também `todos` no escopo do link; o one-click grava só o assunto do link.
 *
 * Remetente: o escritório do link, ou a plataforma ("BlueJus") quando o link não tem escritório.
 */
final class DescadastroDeEmailController extends AbstractController
{
    /** O remetente dos e-mails da plataforma (escopo sem escritório). */
    private const PLATAFORMA = 'BlueJus';

    /** Motivos oferecidos na página (chips do desenho); o texto gravado vem da tradução da chave. */
    private const MOTIVOS = ['demais', 'sem_interesse', 'nao_cadastrei', 'outro'];

    public function __construct(
        private readonly LinkDeDescadastro $link,
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly TenantRepository $tenants,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/email/descadastro', name: LinkDeDescadastro::ROTA, methods: ['GET', 'POST'])]
    public function descadastrar(Request $request): Response
    {
        $pedido = $this->link->ler($request);
        if ($pedido === null) {
            throw new NotFoundHttpException('Link de descadastro inválido.');
        }
        $tenant = $this->escopo($pedido);
        $remetente = $tenant === null ? self::PLATAFORMA : (trim((string) $tenant->getName()) ?: self::PLATAFORMA);

        if (!$request->isMethod('POST')) {
            return $this->pagina('email_descadastro/confirmar.html.twig', [
                'remetente' => $remetente,
                'email' => $pedido->emailMascarado(),
                'acao' => $request->getRequestUri(),
                'motivos' => self::MOTIVOS,
            ]);
        }

        // Botão da página: grava o assunto e, com a opção marcada, o opt-out total. One-click do
        // provedor (RFC 8058): só o assunto do link.
        $doBotao = $request->request->has('confirmar');
        $todos = $doBotao && $request->request->getBoolean('todos');
        $motivo = $doBotao ? $this->motivo($request) : null;
        $this->gravar($tenant, $pedido->email, $pedido->assunto, $doBotao ? 'link_do_email' : 'one_click', $motivo);
        if ($todos) {
            $this->gravar($tenant, $pedido->email, DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        }

        return $this->pagina('email_descadastro/confirmado.html.twig', ['remetente' => $remetente, 'todos' => $todos]);
    }

    /** O escritório do link, ou null para a plataforma; escritório que não existe mais é 404. */
    private function escopo(PedidoDeDescadastro $pedido): ?Tenant
    {
        if ($pedido->tenantId === null) {
            return null;
        }

        $tenant = $this->tenants->find($pedido->tenantId);
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('Link de descadastro inválido.');
        }

        return $tenant;
    }

    /** O motivo escolhido nos chips (opcional): só chave da lista fixa vale; o gravado é o texto dela. */
    private function motivo(Request $request): ?string
    {
        $chave = $request->request->get('motivo');
        if (!is_string($chave) || !in_array($chave, self::MOTIVOS, true)) {
            return null;
        }

        return $this->translator->trans('publicas.descadastro.motivos.' . $chave, [], null, 'pt_BR');
    }

    private function gravar(?Tenant $tenant, string $email, string $assunto, string $origem, ?string $motivo = null): void
    {
        try {
            $this->descadastro->descadastrar($tenant, $email, $assunto, $origem, $motivo);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Link de descadastro inválido.');
        }
    }

    /** @param array<string, mixed> $contexto */
    private function pagina(string $template, array $contexto): Response
    {
        $resposta = $this->render($template, $contexto);
        $resposta->headers->set('X-Robots-Tag', 'noindex');
        $resposta->headers->set('Referrer-Policy', 'no-referrer');

        return $resposta;
    }
}
