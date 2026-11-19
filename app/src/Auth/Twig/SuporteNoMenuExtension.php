<?php

declare(strict_types=1);

namespace App\Auth\Twig;

use App\Auth\Repository\MensagemSuporteRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Entity\Auth\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `suporte_no_menu()`: quantas mensagens do "Falar com suporte" estão pendentes, para o item "Suporte" do menu
 * lateral. Null para quem não lê /suporte/mensagens (a mesma regra da página: o Master ou o destinatário do
 * suporte). Nunca lança: o menu de todas as páginas não pode cair por causa do contador.
 */
final class SuporteNoMenuExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly PaginaDeEntrada $paginaDeEntrada,
        private readonly MensagemSuporteRepository $mensagens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('suporte_no_menu', $this->pendentes(...))];
    }

    public function pendentes(): ?int
    {
        try {
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return null;
            }
            if (!$this->security->isGranted('ROLE_SUPER_ADMIN') && !$this->paginaDeEntrada->ehDestinatarioDoSuporte($user)) {
                return null;
            }

            return $this->mensagens->contarPendentes();
        } catch (\Throwable $falha) {
            $this->logger->warning('Suporte: contador do menu não montou', ['erro' => $falha->getMessage()]);

            return null;
        }
    }
}
