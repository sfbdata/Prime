<?php

declare(strict_types=1);

namespace App\Auth\Controller;

use App\Auth\Enum\ReenvioDeConfirmacao;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\UseCase\ReenviarConfirmacaoDeEmailUseCase;
use App\Entity\Auth\User;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Botão "Reenviar o link de confirmação" (desenho 10), que aparece junto da recusa por e-mail a confirmar (convite
 * de escritório, convite do Trabalho Externo, link externo do Push). Só a própria conta logada pede para si.
 *
 * O teto curto (um link a cada 2 minutos por conta) mora AQUI, e não no UseCase: a automação do servidor pode chamar
 * o UseCase direto, sem CSRF e sem teto. A resposta volta para a página de origem com um flash.
 */
#[IsGranted('IS_AUTHENTICATED')]
final class ContaConfirmacaoController extends AbstractController
{
    public const CSRF = 'conta_confirmacao_reenviar';
    public const INTERVALO = '-2 minutes';

    public const FLASH_ENVIADO = 'Enviamos um novo link de confirmação para o seu e-mail.';
    public const FLASH_AGUARDE = 'Aguarde um instante antes de pedir outro link de confirmação.';
    public const FLASH_JA_CONFIRMADO = 'O seu e-mail já está confirmado.';
    public const FLASH_FALHA = 'Não foi possível enviar o e-mail agora. Tente de novo em instantes.';

    public function __construct(
        private readonly ReenviarConfirmacaoDeEmailUseCase $reenviar,
        private readonly ConfirmacaoEmailRepository $confirmacaoEmailRepository,
        private readonly ClockInterface $relogio,
    ) {
    }

    #[Route('/conta/confirmacao/reenviar', name: 'conta_confirmacao_reenviar', methods: ['POST'])]
    public function reenviar(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$user->isEmailConfirmado() && $this->confirmacaoEmailRepository->houveEmissaoDesde($user, $this->relogio->now()->modify(self::INTERVALO))) {
            $this->addFlash('warning', self::FLASH_AGUARDE);

            return $this->voltar($request);
        }

        [$tipo, $texto] = match ($this->reenviar->executar($user)) {
            ReenvioDeConfirmacao::Enviado => ['success', self::FLASH_ENVIADO],
            ReenvioDeConfirmacao::JaConfirmado => ['info', self::FLASH_JA_CONFIRMADO],
            ReenvioDeConfirmacao::FalhaNoEnvio => ['danger', self::FLASH_FALHA],
        };
        $this->addFlash($tipo, $texto);

        return $this->voltar($request);
    }

    /**
     * Volta para a página de origem só se o Referer é deste mesmo host e o caminho é local (sem `//` nem `\`, que o
     * navegador leria como outro host); senão, a página inicial.
     */
    private function voltar(Request $request): RedirectResponse
    {
        $partes = parse_url((string) $request->headers->get('referer', ''));
        $caminho = is_array($partes) ? ($partes['path'] ?? '') : '';
        $mesmoHost = is_array($partes)
            && in_array($partes['scheme'] ?? '', ['http', 'https'], true)
            && ($partes['host'] ?? null) === $request->getHost();

        if ($mesmoHost && str_starts_with($caminho, '/') && !str_starts_with($caminho, '//') && !str_contains($caminho, '\\')) {
            return $this->redirect($caminho . (isset($partes['query']) ? '?' . $partes['query'] : ''));
        }

        return $this->redirectToRoute('homepage');
    }
}
