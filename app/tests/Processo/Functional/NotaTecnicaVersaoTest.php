<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Processo\Repository\NotaTecnicaVersaoRepository;
use App\Processo\UseCase\EditarNotaTecnicaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Versões da nota técnica (PMC-020): a edição pela rota real grava a versão anterior INTEIRA no
 * banco, presa ao escritório; a edição recusada não grava; e a leitura do repositório não cruza
 * escritório. Só gravação — nenhuma tela mostra as versões (o desenho não mostra).
 */
#[CoversClass(EditarNotaTecnicaUseCase::class)]
#[CoversClass(NotaTecnicaVersaoRepository::class)]
#[Group('processo')]
final class NotaTecnicaVersaoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** Sem o TenantFilter: quem tem de prender ao escritório é a própria consulta do repositório. */
    private function desligarFiltroDeTenant(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
    }

    private function criarNota(Processo $processo, User $autor, Tenant $tenant, string $conteudo): NotaTecnica
    {
        $nota = new NotaTecnica($tenant, $processo, $autor, $conteudo);
        $this->em()->persist($nota);
        $this->em()->flush();

        return $nota;
    }

    private function editar(KernelBrowser $client, Processo $processo, int $notaId, string $conteudo): void
    {
        $this->em()->clear();
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$notaId}/editar", [
            '_token'   => 'TOKEN_processo_nota_tecnica_editar_' . $notaId,
            'conteudo' => $conteudo,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function versoesNoBanco(int $notaId): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, autor_id, conteudo FROM nota_tecnica_versao WHERE nota_tecnica_id = :n ORDER BY id',
            ['n' => $notaId],
        );
    }

    #[TestDox('Duas edições pela rota real: duas versões, com o texto anterior INTEIRO, o escritório e o autor')]
    public function testEdicoesGravamVersoes(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $longo           = '<p>INICIO ' . str_repeat('despacho ', 120) . 'FIM-DA-NOTA</p>';
        $nota            = $this->criarNota($processo, $user, $tenant, $longo);
        $notaId          = (int) $nota->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->editar($client, $processo, $notaId, '<p>Primeira</p>');
        self::assertResponseIsSuccessful();
        $this->editar($client, $processo, $notaId, '<p>Segunda</p>');
        self::assertResponseIsSuccessful();

        $versoes = $this->versoesNoBanco($notaId);
        self::assertCount(2, $versoes);
        self::assertSame($longo, $versoes[0]['conteudo']);
        self::assertGreaterThan(500, mb_strlen((string) $versoes[0]['conteudo']));
        self::assertSame('<p>Primeira</p>', $versoes[1]['conteudo']);
        foreach ($versoes as $v) {
            self::assertSame($tenant->getId(), (int) $v['tenant_id']);
            self::assertSame($user->getId(), (int) $v['autor_id']);
        }
    }

    #[TestDox('Edição recusada (não autor) não grava versão')]
    public function testEdicaoRecusadaNaoGravaVersao(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $outro, $tenant, '<p>Do colega</p>');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->editar($client, $processo, (int) $nota->getId(), '<p>Tentando</p>');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->versoesNoBanco((int) $nota->getId()));
    }

    #[TestDox('Repositório: as versões da nota só saem no escritório dela (o irmão, no próprio, sai)')]
    public function testRepositorioPresoAoEscritorio(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $user, $tenant, '<p>Original</p>');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->editar($client, $processo, (int) $nota->getId(), '<p>Corrigida</p>');
        self::assertResponseIsSuccessful();

        $this->desligarFiltroDeTenant();
        $this->em()->clear();
        /** @var NotaTecnicaVersaoRepository $repo */
        $repo     = static::getContainer()->get(NotaTecnicaVersaoRepository::class);
        $notaLida = $this->em()->find(NotaTecnica::class, $nota->getId());
        self::assertNotNull($notaLida);

        self::assertCount(1, $repo->listarDaNota($notaLida, $this->em()->find(Tenant::class, $tenant->getId())));
        self::assertSame([], $repo->listarDaNota($notaLida, $this->em()->find(Tenant::class, $outro->getId())));
    }
}
