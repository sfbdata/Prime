<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Entity\Auth\User;
use App\Profile\UseCase\ObterAvisoDaOabUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `aviso_da_oab()`: o aviso "Confira o número" da OAB que o Cadastro Nacional dos Advogados não achou, uma vez por
 * sessão, no topo das telas (`layout/_globais/aviso_oab.html.twig`; pedido do dono, 26/11/2026), ou null.
 *
 * A conferência roda uma vez por sessão: a marca {@see self::SESSAO} entra na primeira tela depois do login, com ou sem
 * aviso, e daí em diante não toca mais no banco. No Meu Perfil não conta: lá o aviso já aparece no cartão da OAB, com o
 * atalho para corrigir, e o do topo fica para a próxima tela. Só em GET de página. Uma falha aqui some com o aviso,
 * nunca com a página.
 */
final class AvisoDaOabExtension extends AbstractExtension implements ResetInterface
{
    public const SESSAO = 'perfil_aviso_oab_conferido';

    private bool $montado = false;

    /** @var array{id: ?int, numero: string, uf: string}|null */
    private ?array $aviso = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ObterAvisoDaOabUseCase $obterAviso,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('aviso_da_oab', $this->avisoDaOab(...))];
    }

    /** @return array{id: ?int, numero: string, uf: string}|null */
    public function avisoDaOab(): ?array
    {
        if (!$this->montado) {
            $this->aviso   = $this->montarSemDerrubar();
            $this->montado = true;
        }

        return $this->aviso;
    }

    public function reset(): void
    {
        $this->montado = false;
        $this->aviso   = null;
    }

    /** @return array{id: ?int, numero: string, uf: string}|null */
    private function montarSemDerrubar(): ?array
    {
        $usuario = $this->security->getUser();
        $request = $this->requests->getMainRequest();
        if (!$usuario instanceof User || $request === null || !$request->isMethod('GET') || !$request->hasSession()) {
            return null;
        }
        if (str_starts_with((string) $request->attributes->get('_route'), 'app_profile')) {
            return null;
        }
        $sessao = $request->getSession();
        if ($sessao->get(self::SESSAO) === true) {
            return null;
        }
        $sessao->set(self::SESSAO, true);

        try {
            return $this->obterAviso->executar($usuario);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso da OAB não montado: {erro}', ['erro' => $e::class]);

            return null;
        }
    }
}
