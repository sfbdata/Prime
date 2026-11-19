<?php

declare(strict_types=1);

namespace App\Profile\EventListener;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Repository\UserProfileRepository;
use App\Shared\Formato\FormatoDoIdiomaExtension;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Põe na requisição as "Opções avançadas" da pessoa (spec docs/specs/sistema-em-ingles.md, §2): fuso horário,
 * formato de data e de números, nos atributos que o `FormatoDoIdiomaExtension` lê (o Shared não conhece o Profile).
 * Atributo ausente = o padrão (Brasília; automático, pelo idioma). Depois do firewall, como o
 * {@see IdiomaDaRequisicaoListener}, e com a mesma guarda na sessão (um banco só na primeira requisição; quem salva
 * as opções regrava a sessão). Requisição sem sessão (`_stateless`) e quem não está logado ficam no padrão.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class FormatoDaRequisicaoListener
{
    public const CHAVE_DA_SESSAO = 'bj_formato';

    public function __construct(
        private readonly Security $security,
        private readonly UserProfileRepository $perfis,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        foreach ($this->opcoesDe($request) as $atributo => $valor) {
            if ($valor !== null) {
                $request->attributes->set($atributo, $valor);
            }
        }
    }

    /** Grava na sessão as opções da pessoa (depois que ela salva as "Opções avançadas"). */
    public static function lembrar(Request $request, User $usuario, UserProfile $perfil): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::CHAVE_DA_SESSAO, ['usuario' => $usuario->getId()] + self::opcoes($perfil));
        }
    }

    /** @return array<string, string|null> */
    private function opcoesDe(Request $request): array
    {
        if ($request->attributes->getBoolean('_stateless')) {
            return [];
        }
        $usuario = $this->security->getUser();
        if (!$usuario instanceof User || !$request->hasSession()) {
            return [];
        }

        $guardado = $request->getSession()->get(self::CHAVE_DA_SESSAO);
        if (is_array($guardado) && ($guardado['usuario'] ?? null) === $usuario->getId()) {
            unset($guardado['usuario']);

            return array_map(static fn (mixed $v): ?string => is_string($v) ? $v : null, $guardado);
        }

        $perfil = $this->perfis->buscarPorUsuario($usuario);
        $opcoes = $perfil !== null ? self::opcoes($perfil) : [];
        $request->getSession()->set(self::CHAVE_DA_SESSAO, ['usuario' => $usuario->getId()] + $opcoes);

        return $opcoes;
    }

    /** @return array<string, string|null> */
    private static function opcoes(UserProfile $perfil): array
    {
        return [
            FormatoDoIdiomaExtension::ATRIBUTO_FUSO   => $perfil->getFusoHorario()?->value,
            FormatoDoIdiomaExtension::ATRIBUTO_DATA   => $perfil->getFormatoData()?->value,
            FormatoDoIdiomaExtension::ATRIBUTO_NUMERO => $perfil->getFormatoNumero()?->value,
        ];
    }
}
