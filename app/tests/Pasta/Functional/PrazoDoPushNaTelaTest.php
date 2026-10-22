<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\DTO\PastaPrazoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O prazo calculado do Push na tela (docs/specs/prazo-do-push.md §2 e §4): no trilho da pasta com a origem "Push
 * processual" e o selo "prazo calculado, confira"; nos filtros do Expediente pelo mesmo caminho dos outros prazos.
 */
#[CoversClass(PastaPrazoOutput::class)]
#[CoversClass(PastaPrazo::class)]
final class PrazoDoPushNaTelaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('o trilho mostra o prazo do Push com a origem "Push processual", o selo "prazo calculado, confira" e sem as ações do manual')]
    public function testTrilhoMostraOSelo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->prazoDoPush($tenant, $pasta, PastaPrazo::TIPO_PRAZO, $this->dia('+2 days'), $this->dia('-5 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $linha = $c->filter('[data-trilho="prazos"] .ps-prazo');
        self::assertCount(1, $linha);
        self::assertSame('Prazo do Push: Réplica (parte autora)', trim($linha->filter('.ps-linha-titulo')->text()));
        self::assertStringContainsString('Sem responsável · ' . $this->dia('+2 days')->format('d/m') . ' · Push processual', $linha->filter('.ps-linha-meta')->text());
        self::assertSame('prazo calculado, confira', trim($linha->filter('.ps-linha-meta > .ps-prazo-calculado')->text()));
        self::assertCount(0, $linha->filter('.js-prazo-cumprir, .js-prazo-editar, .js-prazo-excluir'));
        self::assertStringContainsString('· Push processual · Pasta', (string) $linha->filter('.js-ps-copiar')->attr('data-ps-copiar'));
    }

    #[TestDox('prazo manual não ganha o selo do Push')]
    public function testManualSemSelo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $manual = new PastaPrazo($tenant, $pasta, $user);
        $manual->definir(PastaPrazo::TIPO_PRAZO, 'Prazo do cliente', $this->dia('+2 days'), null, null);
        $this->em()->persist($manual);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertCount(1, $c->filter('[data-trilho="prazos"] .ps-prazo'));
        self::assertCount(0, $c->filter('.ps-prazo-calculado'));
    }

    #[TestDox('"Expediente com prazo" conta a pasta com prazo do Push em aberto; a pasta sem prazo fica de fora')]
    public function testFiltroComPrazo(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $comPush = $this->criarPasta($tenant, 'PUSH');
        $semPrazo = $this->criarPasta($tenant, 'SEM');
        $this->prazoDoPush($tenant, $comPush, PastaPrazo::TIPO_PRAZO, $this->dia('+4 days'), $this->dia('-3 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=prazo');

        self::assertStringContainsString($comPush->getNup(), $html);
        self::assertStringNotContainsString($semPrazo->getNup(), $html);
    }

    #[TestDox('"Prazo findou nos últimos 10 dias sem resposta" conta o prazo do Push vencido sem ação depois')]
    public function testFiltroFindou(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $vencido = $this->criarPasta($tenant, 'VENC');
        $this->prazoDoPush($tenant, $vencido, PastaPrazo::TIPO_PRAZO, $this->dia('-3 days'), $this->dia('-20 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertStringContainsString($vencido->getNup(), $this->painel('?expediente=findou'));
    }

    #[TestDox('outra publicação do Push depois da data não é resposta: o prazo do Push vencido continua em "findou"')]
    public function testPublicacaoDepoisNaoTiraDoFindou(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'FIND');
        $this->prazoDoPush($tenant, $pasta, PastaPrazo::TIPO_PRAZO, $this->dia('-3 days'), $this->dia('-20 days'));
        $this->prazoDoPush($tenant, $pasta, PastaPrazo::TIPO_PRAZO, $this->dia('+10 days'), $this->dia('-1 day'));
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertStringContainsString($pasta->getNup(), $this->painel('?expediente=findou'));
    }

    #[TestDox('a pasta só com prazo do Push não entra em "Respondidos nos últimos 10 dias"')]
    public function testPushNaoERespostaEmResp10(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'R10');
        $this->prazoDoPush($tenant, $pasta, PastaPrazo::TIPO_PRAZO, $this->dia('+5 days'), $this->dia('-2 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertStringNotContainsString($pasta->getNup(), $this->painel('?expediente=resp10'));
    }

    #[TestDox('o prazo manual criado depois da data continua contando como atividade: tira do "findou" e entra em "Respondidos"')]
    public function testManualDepoisContinuaSendoAtividade(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant, 'MAN');
        $this->prazoDoPush($tenant, $pasta, PastaPrazo::TIPO_PRAZO, $this->dia('-3 days'), $this->dia('-20 days'));
        $manual = new PastaPrazo($tenant, $pasta, $user);
        $manual->definir(PastaPrazo::TIPO_PRAZO, 'Prazo do cliente', $this->dia('+5 days'), null, null);
        $this->em()->persist($manual);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertStringNotContainsString($pasta->getNup(), $this->painel('?expediente=findou'));
        self::assertStringContainsString($pasta->getNup(), $this->painel('?expediente=resp10'));
    }

    #[TestDox('a audiência lida do Push entra no filtro Audiência')]
    public function testFiltroAudiencia(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $comAudiencia = $this->criarPasta($tenant, 'AUD');
        $soPrazo = $this->criarPasta($tenant, 'PRZ');
        $this->prazoDoPush($tenant, $comAudiencia, PastaPrazo::TIPO_AUDIENCIA, $this->dia('+10 days'), $this->dia('-2 days'));
        $this->prazoDoPush($tenant, $soPrazo, PastaPrazo::TIPO_PRAZO, $this->dia('+10 days'), $this->dia('-2 days'));
        $this->logarComTenant($this->client, $user, $tenant);

        $html = $this->painel('?expediente=aud');

        self::assertStringContainsString($comAudiencia->getNup(), $html);
        self::assertStringNotContainsString($soPrazo->getNup(), $html);
    }

    private function prazoDoPush(Tenant $tenant, Pasta $pasta, string $tipo, \DateTimeImmutable $data, \DateTimeImmutable $disponibilizadaEm): void
    {
        $titulo = $tipo === PastaPrazo::TIPO_AUDIENCIA ? 'Audiência do Push' : 'Prazo do Push: Réplica (parte autora)';
        $this->em()->persist(PastaPrazo::doPush($tenant, $pasta, 1, $disponibilizadaEm, $tipo, $titulo, $data, null, $tipo === PastaPrazo::TIPO_PRAZO));
        $this->em()->flush();
    }

    private function painel(string $query): string
    {
        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral' . $query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function dia(string $quando): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today ' . $quando);
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo Push ' . uniqid());
        $this->em()->persist($tenant);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('prazo_push_' . uniqid() . '@test.com');
        $user->setFullName('Admin Push');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $rotulo = ''): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('PZP' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
