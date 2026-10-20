<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AcessoAoLinkExternoDoPush;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fixtures do link externo do Push sobre as da aba Push (`CriaFixturesPushDaPastaTrait`): pasta com
 * processo e publicação, o link já gravado e um CSRF previsível (`TOKEN_<id>`).
 */
trait CriaLinkExternoDoPushTrait
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DO_LINK = '07011345720258070007';

    /** @return array{Pasta, Processo} pasta com processo vinculado e uma publicação */
    private function criarPastaComPush(Tenant $tenant, string $teor = 'Teor da publicação', string $numero = self::NUMERO_DO_LINK): array
    {
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, $numero);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, (string) random_int(10000000, 99999999), $numero, '2026-10-10', $processo);
        $pub->setTexto($teor);
        $this->em()->flush();

        return [$pasta, $processo];
    }

    private function criarLink(
        Tenant $tenant,
        Pasta $pasta,
        Processo $processo,
        User $criador,
        ?string $codigo = null,
        string $criadoHa = 'now',
        bool $incluirAnalises = false,
    ): LinkExternoDoPush {
        $link = new LinkExternoDoPush(
            $tenant,
            $pasta,
            $processo,
            'bluejus-teste-' . substr(bin2hex(random_bytes(6)), 0, 8),
            $codigo,
            true,
            $incluirAnalises,
            $criador,
            new \DateTimeImmutable($criadoHa),
        );
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    private function contarAcessos(LinkExternoDoPush $link): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM push_link_externo_acesso WHERE link_id = :id',
            ['id' => $link->getId()],
        );
    }

    private function registrarAcesso(LinkExternoDoPush $link, string $quando): void
    {
        $this->em()->persist(new AcessoAoLinkExternoDoPush($link, $link->getTenant(), new \DateTimeImmutable($quando)));
        $this->em()->flush();
    }

    private function instalarCsrfPrevisivel(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }
}
