<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Entity\Auth\User;
use App\Profile\UseCase\ObterAvisoDaOabUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `aviso_da_oab()`: o aviso da OAB Divergente da própria pessoa (não encontrada no CNA, nome ou situação diferente, ou
 * formato inválido), uma vez por sessão, no topo das telas do sistema (`layout/_globais/aviso_oab.html.twig`; pedido
 * do dono, 26/11/2026), ou null. `marcar_aviso_da_oab_mostrado()`: o parcial chama depois de desenhar o aviso, e só
 * então a marca {@see self::SESSAO} é gravada (o aviso que não foi desenhado não gasta a vez).
 *
 * Só na página principal (não em sub-requisição), em GET, de quem está logado, e só nas telas do sistema: as rotas que
 * o access_control do security.yaml abre ao público (login e "entrando", assinatura pelo link, validação do ponto,
 * Push compartilhado, cadastro, convites...) ficam de fora, lidas do próprio mapa de acesso, sem lista duplicada.
 * No Meu Perfil também não: lá o aviso já aparece no cartão da OAB. Uma falha aqui some com o aviso, nunca com a página.
 */
final class AvisoDaOabExtension extends AbstractExtension implements ResetInterface
{
    public const SESSAO = 'perfil_aviso_oab_mostrado';

    private bool $montado = false;

    /** @var array{id: ?int, numero: string, uf: string, motivo: string}|null */
    private ?array $aviso = null;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requests,
        private readonly ObterAvisoDaOabUseCase $obterAviso,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'security.access_map')]
        private readonly AccessMapInterface $mapaDeAcesso,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('aviso_da_oab', $this->avisoDaOab(...)),
            new TwigFunction('marcar_aviso_da_oab_mostrado', $this->marcarMostrado(...)),
        ];
    }

    /** @return array{id: ?int, numero: string, uf: string, motivo: string}|null */
    public function avisoDaOab(): ?array
    {
        if (!$this->montado) {
            $this->aviso   = $this->montarSemDerrubar();
            $this->montado = true;
        }

        return $this->aviso;
    }

    public function marcarMostrado(): void
    {
        $request = $this->paginaPrincipal();
        if ($this->aviso !== null && $request !== null) {
            $request->getSession()->set(self::SESSAO, true);
        }
    }

    public function reset(): void
    {
        $this->montado = false;
        $this->aviso   = null;
    }

    /** @return array{id: ?int, numero: string, uf: string, motivo: string}|null */
    private function montarSemDerrubar(): ?array
    {
        $usuario = $this->security->getUser();
        $request = $this->paginaPrincipal();
        if (!$usuario instanceof User || $request === null || !$request->isMethod('GET')) {
            return null;
        }
        if ($request->getSession()->get(self::SESSAO) === true || !$this->telaDoSistema($request)) {
            return null;
        }

        try {
            return $this->obterAviso->executar($usuario);
        } catch (\Throwable $e) {
            $this->logger->error('Aviso da OAB não montado: {erro}', ['erro' => $e::class]);

            return null;
        }
    }

    /** A requisição principal, com sessão; null numa sub-requisição (fragmento) ou sem sessão. */
    private function paginaPrincipal(): ?Request
    {
        $principal = $this->requests->getMainRequest();
        if ($principal === null || $this->requests->getCurrentRequest() !== $principal || !$principal->hasSession()) {
            return null;
        }

        return $principal;
    }

    /** Fora do Meu Perfil e fora das rotas que o access_control abre ao público (PUBLIC_ACCESS). */
    private function telaDoSistema(Request $request): bool
    {
        if (str_starts_with((string) $request->attributes->get('_route'), 'app_profile')) {
            return false;
        }
        [$papeis] = $this->mapaDeAcesso->getPatterns($request);

        return $papeis !== null && $papeis !== [] && !\in_array('PUBLIC_ACCESS', $papeis, true);
    }
}
