<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Repository\PastaTimelineVisitaRepository;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\UseCase\RegistrarVisitaDaTimelineUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Enquanto você estava fora" na Timeline inteligente (PAS-043): a visita por usuário e pasta
 * (`pasta_timeline_visita`), gravada ao abrir o painel, e o aviso que sai dela.
 *
 * O teste de outro escritório com o TenantFilter LIGADO descreve o sistema, mas o 404 pode vir do
 * filtro (o resolver nem acha a pasta). Os testes `...SemTenantFilter` desligam o filtro dentro da
 * requisição para provar o guarda explícito do controller (`$pasta->getTenant() !== $tenant`).
 *
 * Guardas da escrita: pasta do escritório (404, com SUPER_ADMIN para o 404 só poder vir da
 * conferência de dono), CSRF (403), permissão de VER (403 × 200 no mesmo escritório) e lápide
 * (recusa do listener). Isolamento provado com o recurso irmão: a visita a B não muda A.
 */
#[CoversClass(PastaTimelineController::class)]
#[CoversClass(RegistrarVisitaDaTimelineUseCase::class)]
#[CoversClass(PastaTimelineVisitaRepository::class)]
#[CoversClass(MontadorDaTimelineInteligente::class)]
#[Group('pasta')]
final class PastaTimelineVisitaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use DesligaTenantFilterNaRequisicao;

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

    /** @return array<string, mixed>|null */
    private function visitar(KernelBrowser $client, Pasta $pasta, ?string $token = null): ?array
    {
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $pasta->getId() . '/timeline/visita', [
            '_token' => $token ?? 'TOKEN_pasta_timeline_visita_' . $pasta->getId(),
        ]);

        $corpo = (string) $client->getResponse()->getContent();

        return json_validate($corpo) ? json_decode($corpo, true, 512, JSON_THROW_ON_ERROR) : null;
    }

    /** @return array<string, mixed> */
    private function timeline(KernelBrowser $client, Pasta $pasta, array $query = []): array
    {
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline' . ($query !== [] ? '?' . http_build_query($query) : ''));
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Põe a visita no passado: a abertura "de antes" fica em `anterior`, a mais recente em `ultima`. */
    private function visitaNoBanco(Pasta $pasta, User $usuario, Tenant $tenant, string $anterior, string $ultima): void
    {
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO pasta_timeline_visita (tenant_id, user_id, pasta_id, vista_em, vista_anterior_em)
             VALUES (:t, :u, :p, :ultima, :anterior)',
            ['t' => $tenant->getId(), 'u' => $usuario->getId(), 'p' => $pasta->getId(), 'ultima' => $ultima, 'anterior' => $anterior],
        );
    }

    /** @return array<string, mixed>|false */
    private function linha(Pasta $pasta, User $usuario): array|false
    {
        return $this->em()->getConnection()->fetchAssociative(
            'SELECT tenant_id, vista_em, vista_anterior_em FROM pasta_timeline_visita WHERE pasta_id = :p AND user_id = :u',
            ['p' => $pasta->getId(), 'u' => $usuario->getId()],
        );
    }

    private function registrar(Pasta $pasta, User $autor, Tenant $tenant, string $texto, string $quando): void
    {
        $msg = (new PastaMensagem())->setPasta($pasta)->setAutor($autor)->setTenant($tenant)->setConteudo($texto);
        (new \ReflectionProperty(PastaMensagem::class, 'criadaEm'))->setValue($msg, new \DateTimeImmutable($quando));
        $this->em()->persist($msg);
        $this->em()->flush();
    }

    private function colega(Tenant $tenant, string $nome): User
    {
        [$colega] = $this->criarAdmin();
        $colega->setFullName($nome);
        $this->em()->persist(new UserTenant($colega, $tenant));
        $this->em()->flush();

        return $colega;
    }

    #[TestDox('1ª abertura: grava a visita sem anterior; 2ª: a de antes vira a anterior (e é devolvida)')]
    public function testAberturasSucessivas(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        self::assertSame(['anterior' => null], $this->visitar($client, $pasta));
        self::assertResponseIsSuccessful();
        $primeira = $this->linha($pasta, $user);
        self::assertNotFalse($primeira);
        self::assertSame($tenant->getId(), (int) $primeira['tenant_id']);
        self::assertNull($primeira['vista_anterior_em']);

        // A primeira abertura vai para o passado, para a segunda ter o que deslocar.
        $this->em()->getConnection()->executeStatement(
            "UPDATE pasta_timeline_visita SET vista_em = '2026-01-02 03:04:05' WHERE pasta_id = :p",
            ['p' => $pasta->getId()],
        );

        $resposta = $this->visitar($client, $pasta);
        self::assertResponseIsSuccessful();
        self::assertNotNull($resposta['anterior']);
        self::assertStringStartsWith('2026-01-02T03:04:05', (string) $resposta['anterior']);

        $segunda = $this->linha($pasta, $user);
        self::assertSame('2026-01-02 03:04:05', $segunda['vista_anterior_em']);
        self::assertNotSame('2026-01-02 03:04:05', $segunda['vista_em']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_timeline_visita WHERE pasta_id = :p', ['p' => $pasta->getId()]));
    }

    #[TestDox('Aviso: conta só o que OUTRA pessoa fez depois da visita anterior; o meu e o de antes ficam fora')]
    public function testAvisoContaSoOutrasPessoasDepoisDaVisita(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->colega($tenant, 'COLEGA DA VISITA');
        $pasta           = $this->criarPasta($tenant);

        $this->registrar($pasta, $colega, $tenant, 'ANTES-DA-VISITA', '2026-08-10 10:00');
        $this->registrar($pasta, $colega, $tenant, 'DEPOIS-DO-COLEGA-1', '2026-08-20 10:00');
        $this->registrar($pasta, $colega, $tenant, 'DEPOIS-DO-COLEGA-2', '2026-08-21 10:00');
        $this->registrar($pasta, $user, $tenant, 'DEPOIS-MEU', '2026-08-22 10:00');
        $this->visitaNoBanco($pasta, $user, $tenant, '2026-08-15 12:00:00', '2026-09-01 12:00:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta);

        self::assertSame(2, $dados['novos']);
        self::assertSame(['registro' => 2], $dados['novosPorCategoria']);
        self::assertStringStartsWith('2026-08-15T12:00:00', (string) $dados['foraDesde']);
        // "Ver resumo": a partir da visita, com o meu registro também (o desenho resume tudo dali);
        // o de antes da visita não entra (são 4 registros na pasta).
        self::assertStringStartsWith('3 registro(s) na pasta.', (string) $dados['resumoDesde']);
        self::assertStringStartsWith('4 registro(s) na pasta.', (string) $dados['resumo']);

        // O aviso é sobre a lista inteira: trocar de chip não muda a contagem.
        self::assertSame(2, $this->timeline($client, $pasta, ['categoria' => 'documento'])['novos']);
    }

    #[TestDox('Sem visita anterior (nunca abriu, ou só abriu uma vez): nada é novo, e o `desde` da URL é ignorado')]
    public function testSemVisitaAnteriorNadaENovo(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->colega($tenant, 'COLEGA SEM VISITA');
        $pasta           = $this->criarPasta($tenant);
        $this->registrar($pasta, $colega, $tenant, 'DO-COLEGA', '2026-08-20 10:00');

        $this->logarComTenant($client, $user, $tenant);
        $dados = $this->timeline($client, $pasta, ['desde' => '2020-01-01T00:00:00Z']);

        self::assertSame(0, $dados['novos']);
        self::assertSame([], $dados['novosPorCategoria']);
        self::assertNull($dados['foraDesde']);
        self::assertNull($dados['resumoDesde']);
    }

    #[TestDox('A visita é por pasta e por usuário: a minha à pasta B e a do colega à A não mudam o meu aviso na A')]
    public function testVisitaEPorPastaEPorUsuario(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $colega          = $this->colega($tenant, 'COLEGA DA PASTA B');
        $pastaA          = $this->criarPasta($tenant);
        $pastaB          = $this->criarPasta($tenant);
        $this->registrar($pastaA, $colega, $tenant, 'NA-A', '2026-08-20 10:00');
        $this->registrar($pastaB, $colega, $tenant, 'NA-B', '2026-08-20 10:00');
        $this->visitaNoBanco($pastaB, $user, $tenant, '2026-08-15 12:00:00', '2026-09-01 12:00:00');
        $this->visitaNoBanco($pastaA, $colega, $tenant, '2026-08-15 12:00:00', '2026-09-01 12:00:00');

        $this->logarComTenant($client, $user, $tenant);

        self::assertSame(0, $this->timeline($client, $pastaA)['novos']);
        // O irmão: a mesma visita, na pasta certa, conta.
        self::assertSame(1, $this->timeline($client, $pastaB)['novos']);
    }

    #[TestDox('Pasta de outro escritório: 404 e nada gravado (a própria grava)')]
    public function testOutroEscritorioDa404(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $minha           = $this->criarPasta($tenant);
        $alheia          = $this->criarPasta($outro);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->visitar($client, $alheia);
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->linha($alheia, $user));

        $this->visitar($client, $minha);
        self::assertResponseIsSuccessful();
        self::assertNotFalse($this->linha($minha, $user));
    }

    #[TestDox('Visita a pasta de outro escritório, SEM o TenantFilter e sem token: 404 do guarda do controller, antes do CSRF')]
    public function testVisitaDeOutroEscritorioSemTenantFilterDa404AntesDoCsrf(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $alheia          = $this->criarPasta($outro);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->desligarTenantFilterNaProximaRequisicao();

        // Token inválido de propósito: sem o guarda de dono, a resposta seria o 403 do CSRF (e o
        // 403 confirmaria que o id existe). O UseCase, que também confere o dono, nem é alcançado.
        $this->visitar($client, $alheia, 'token_invalido');

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->linha($alheia, $user));
    }

    #[TestDox('Timeline de pasta de outro escritório, SEM o TenantFilter: 404 do guarda do controller')]
    public function testTimelineDeOutroEscritorioSemTenantFilterDa404(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();
        $alheia          = $this->criarPasta($outro);
        $alheiaId        = $alheia->getId();

        $this->logarComTenant($client, $user, $tenant);
        $this->desligarTenantFilterNaProximaRequisicao();
        $client->request('GET', '/pasta/' . $alheiaId . '/timeline');

        $this->assertTenantFilterFoiDesligado();
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: 403 e nada gravado')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->visitar($client, $pasta, 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->linha($pasta, $user));
    }

    #[TestDox('Quem não pode ver a pasta: 403 e nada gravado; quem só vê: grava')]
    public function testPermissaoDeVer(): void
    {
        $client     = static::createClient();
        $client->disableReboot();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $leitor     = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $pasta      = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $semNada, $tenant);
        $this->visitar($client, $pasta);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->linha($pasta, $semNada));

        $this->logarComTenant($client, $leitor, $tenant);
        $this->visitar($client, $pasta);
        self::assertResponseIsSuccessful();
        self::assertNotFalse($this->linha($pasta, $leitor));
    }

    #[TestDox('Pasta excluída (lápide): a visita é recusada e nada é gravado; a timeline continua legível')]
    public function testLapideRecusaAVisita(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable('2026-09-01 10:00'));
        $this->em()->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $this->visitar($client, $pasta);

        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertFalse($this->linha($pasta, $user));
        $this->timeline($client, $pasta);
    }
}
