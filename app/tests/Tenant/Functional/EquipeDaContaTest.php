<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\EventListener\TenantContextValidatorListener;
use App\Tenant\Controller\EquipeDaContaController;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Equipe da conta (desenho bj-contas.js; spec docs/specs/equipe-da-conta.md, risco ALTO): quem vê, quem altera a
 * situação, as recusas, o efeito no acesso (barreira (d)) e o demitir.
 */
#[CoversClass(EquipeDaContaController::class)]
#[CoversClass(TenantContextValidatorListener::class)]
final class EquipeDaContaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $primario;
    private User $secundario;
    private User $master;
    private UserTenant $vinculoSecundario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Escritório Equipe ' . uniqid());
        $this->em->persist($this->tenant);
        $sistema    = $this->perfil(true);
        $secundario = $this->perfil(false);

        $this->primario   = $this->usuario('Farlei Assis da Rocha');
        $this->secundario = $this->usuario('Jéssica Martins');
        $this->master     = $this->usuario('Master Equipe', ['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vincular($this->primario, $sistema);
        $this->vinculoSecundario = $this->vincular($this->secundario, $secundario);
        $this->vincular($this->master, $secundario);
        $this->tenant->setCriadoPor($this->primario);
        $this->em->flush();
    }

    #[TestDox('o primário vê a equipe: primário primeiro, selos e situação; menu só para quem pode ser alterado')]
    public function testPrimarioVeAEquipe(): void
    {
        $linhas = $this->equipe($this->primario)->filter('.mu-minha-conta > .mu-equipe > .mu-membro');

        self::assertCount(3, $linhas);
        self::assertSame('Farlei Assis da Rocha', trim($linhas->eq(0)->filter('.mu-membro-nome')->text()));
        self::assertSame('PRIMÁRIO', trim($linhas->eq(0)->filter('.mu-selo-equipe')->text()));
        self::assertCount(0, $linhas->eq(0)->filter('.mu-situacao'), 'o primário não tem selo de situação');
        $jessica = $linhas->reduce(static fn (Crawler $l): bool => $l->attr('data-nome') === 'Jéssica Martins');
        self::assertSame('APROVADO', trim($jessica->filter('.mu-situacao')->text()));
        self::assertSame('1', $jessica->attr('data-acoes'));
        $master = $linhas->reduce(static fn (Crawler $l): bool => $l->attr('data-nome') === 'Master Equipe');
        self::assertSame('0', $master->attr('data-acoes'), 'o Master não pode ser alterado pelo primário');
        self::assertSame('0', $linhas->eq(0)->attr('data-acoes'));
    }

    #[TestDox('secundário não vê a equipe (403) nem altera situação (422)')]
    public function testSecundarioNaoGere(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $this->client->request('GET', '/escritorio/equipe');
        self::assertResponseStatusCodeSame(403);

        $this->postar($this->secundario, $this->vinculoSecundario->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SituacaoNaEquipe::Aprovado, $this->situacaoDe($this->vinculoSecundario));
    }

    #[TestDox('suspender: a pessoa perde o acesso, cai na seleção com a mensagem do desenho, e reaprovar devolve')]
    public function testSuspenderBloqueiaOAcesso(): void
    {
        $this->postar($this->primario, $this->vinculoSecundario->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseIsSuccessful();
        self::assertSame(SituacaoNaEquipe::Suspenso, $this->situacaoDe($this->vinculoSecundario));

        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $this->client->request('GET', '/perfil');
        self::assertResponseRedirects('/escritorio/selecionar');
        $esperado = 'Seu acesso ao escritório ' . $this->tenant->getName() . ' está suspenso pelo usuário primário.';
        self::assertStringContainsString($esperado, implode(' ', $this->client->getRequest()->getSession()->getFlashBag()->peek('warning')));
        $selecao = $this->client->followRedirect();
        self::assertStringContainsString($esperado, $selecao->filter('[data-acesso-bloqueado]')->text());

        $this->postar($this->primario, $this->vinculoSecundario->getId(), 'situacao', ['situacao' => 'aprovado']);
        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('em análise: mensagem própria no acesso')]
    public function testEmAnalise(): void
    {
        $this->postar($this->primario, $this->vinculoSecundario->getId(), 'situacao', ['situacao' => 'em_analise']);

        $this->logarComTenant($this->client, $this->secundario, $this->tenant);
        $this->client->request('GET', '/perfil');
        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertStringContainsString('está em análise pelo usuário primário', implode(' ', $this->client->getRequest()->getSession()->getFlashBag()->peek('warning')));
    }

    #[TestDox('recusas: o primário, o Master e a si mesmo não se alteram; vínculo de outro escritório é 404; sem token é 403')]
    public function testRecusas(): void
    {
        $vinculoPrimario = $this->vinculoDe($this->primario);
        $vinculoMaster   = $this->vinculoDe($this->master);

        $this->postar($this->primario, $vinculoPrimario->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($this->primario, $vinculoMaster->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Master não pode ser alterada', (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['erro'] ?? ''));

        $outro = new Tenant();
        $outro->setName('Outro ' . uniqid());
        $this->em->persist($outro);
        $alheio = new UserTenant($this->usuario('Pessoa Alheia'), $outro);
        $this->em->persist($alheio);
        $this->em->flush();
        $this->postar($this->primario, $alheio->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $this->primario, $this->tenant);
        $this->client->request('POST', '/escritorio/equipe/' . $this->vinculoSecundario->getId() . '/situacao', [], [], ['CONTENT_TYPE' => 'application/json'], '{"situacao":"suspenso"}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(SituacaoNaEquipe::Aprovado, $this->situacaoDe($this->vinculoSecundario));
    }

    #[TestDox('demitir remove o vínculo (mesma remoção da tela de equipe); o primário não pode ser demitido')]
    public function testDemitir(): void
    {
        $id = (int) $this->vinculoSecundario->getId();
        $this->postar($this->primario, $id, 'demitir', []);
        self::assertResponseIsSuccessful();
        self::assertNull($this->em->getRepository(UserTenant::class)->find($id));

        $this->postar($this->primario, $this->vinculoDe($this->primario)->getId(), 'demitir', []);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('o Master gere a equipe de um escritório onde tem vínculo secundário')]
    public function testMasterGere(): void
    {
        self::assertCount(3, $this->equipe($this->master)->filter('.mu-membro'));
        $this->postar($this->master, $this->vinculoSecundario->getId(), 'situacao', ['situacao' => 'suspenso']);
        self::assertResponseIsSuccessful();
    }

    private function equipe(User $quem): Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $tela = $this->client->request('GET', '/escritorio/equipe');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** @param array<string, string> $dados */
    private function postar(User $quem, ?int $vinculoId, string $acao, array $dados): void
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $token = (string) $this->client->request('GET', '/perfil')->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('POST', '/escritorio/equipe/' . $vinculoId . '/' . $acao, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token,
        ], (string) json_encode($dados));
    }

    private function situacaoDe(UserTenant $vinculo): SituacaoNaEquipe
    {
        return SituacaoNaEquipe::from((string) $this->em->getConnection()->fetchOne('SELECT situacao FROM user_tenant WHERE id = ?', [$vinculo->getId()]));
    }

    private function vinculoDe(User $user): UserTenant
    {
        return $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId(), 'tenant' => $this->tenant->getId()]);
    }

    private function perfil(bool $sistema): TenantRole
    {
        $p = new TenantRole();
        $p->setTenant($this->tenant);
        $p->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)');
        $p->setIsSystem($sistema);
        $this->em->persist($p);

        return $p;
    }

    /** @param list<string> $papeis */
    private function usuario(string $nome, array $papeis = ['ROLE_USER']): User
    {
        $u = new User();
        $u->setEmail(uniqid('equipe_') . '@test.com');
        $u->setFullName($nome);
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em->persist($u);

        return $u;
    }

    private function vincular(User $u, TenantRole $perfil): UserTenant
    {
        $v = new UserTenant($u, $this->tenant);
        $v->setTenantRole($perfil);
        $this->em->persist($v);

        return $v;
    }
}
