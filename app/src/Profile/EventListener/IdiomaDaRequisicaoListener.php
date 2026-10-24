<?php

declare(strict_types=1);

namespace App\Profile\EventListener;

use App\Entity\Auth\User;
use App\Profile\Enum\Idioma;
use App\Profile\Repository\UserProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Põe na requisição o idioma escolhido pela pessoa no Meu Perfil (spec idioma-do-sistema, entrega 1): o locale do
 * pedido e o do tradutor, das datas e dos números (`LocaleSwitcher`). Depois do firewall (prioridade 8), que é
 * quem sabe quem está logado. Deslogado: português.
 *
 * O idioma fica guardado na sessão junto com o id da pessoa (um banco só na primeira requisição); quem troca o
 * idioma regrava a sessão. Idioma gravado mas ainda não liberado vale como português ({@see Idioma::emUso()}).
 *
 * Rota com locale fixo (`#[Route(locale: 'pt_BR')]`, o `_locale` da rota) fica nele: é o caso dos Termos de Uso e da
 * Política de Privacidade, que só existem em português (o aceite e a ciência estão amarrados ao texto pt).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class IdiomaDaRequisicaoListener
{
    public const CHAVE_DA_SESSAO = 'bj_idioma';

    public function __construct(
        private readonly Security $security,
        private readonly UserProfileRepository $perfis,
        private readonly LocaleSwitcher $localeSwitcher,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $fixo = $request->attributes->get('_locale');
        $locale = is_string($fixo) && $fixo !== '' ? $fixo : $this->idiomaDe($request)->locale();
        $request->setLocale($locale);
        $this->localeSwitcher->setLocale($locale);
    }

    /** Grava na sessão o idioma da pessoa (depois que ela troca no Meu Perfil). */
    public static function lembrar(Request $request, User $usuario, Idioma $idioma): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::CHAVE_DA_SESSAO, ['usuario' => $usuario->getId(), 'idioma' => $idioma->value]);
        }
    }

    private function idiomaDe(Request $request): Idioma
    {
        $usuario = $this->security->getUser();
        if (!$usuario instanceof User || !$request->hasSession()) {
            return Idioma::PADRAO;
        }

        $guardado = $request->getSession()->get(self::CHAVE_DA_SESSAO);
        if (is_array($guardado) && ($guardado['usuario'] ?? null) === $usuario->getId()) {
            return Idioma::emUso(is_string($guardado['idioma'] ?? null) ? $guardado['idioma'] : null);
        }

        $idioma = $this->perfis->buscarPorUsuario($usuario)?->getIdioma() ?? Idioma::PADRAO;
        self::lembrar($request, $usuario, $idioma);

        return $idioma;
    }
}
