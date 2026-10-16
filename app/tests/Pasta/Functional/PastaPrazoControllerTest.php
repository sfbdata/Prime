<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaPrazoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaPrazo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Prazos e audiências registrados à mão na pasta (E12b, desenho 1.2.3 "Próximos prazos"): rotas, cartão do sistema
 * no Registro, a lista do trilho e os filtros da Lista do Expediente que passam a enxergá-los.
 */
#[CoversClass(PastaPrazoController::class)]
final class PastaPrazoControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    #[TestDox('salvar uma audiência grava, avisa "Audiência registrada" e escreve o cartão do sistema no Registro')]
    public function testSalvarAudiencia(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['tipo' => 'audiencia', 'titulo' => 'Audiência de conciliação', 'data' => '2030-05-20', 'hora' => '14:30', 'responsavel' => 'Dra. Ana']);

        self::assertResponseIsSuccessful();
        self::assertSame('Audiência registrada', $this->resposta()['aviso']);
        $prazo = $this->em()->getRepository(PastaPrazo::class)->findOneBy(['pasta' => $pasta]);
        self::assertTrue($prazo->isAudiencia());
        self::assertSame('14:30', $prazo->getHora());
        self::assertSame('Dra. Ana', $prazo->getResponsavel());
        $cartao = $this->em()->getRepository(PastaMensagem::class)->findOneBy(['pasta' => $pasta, 'sistema' => 'prazo']);
        self::assertNotNull($cartao);
        self::assertStringContainsString('Audiência agendada: Audiência de conciliação · 20/05/2030 14:30', $cartao->getConteudo());
    }

    #[TestDox('o trilho da pasta mostra o prazo manual com "Audiência · ", a origem "Manual" e o menu de cumprir, editar e excluir')]
    public function testTrilhoMostraOPrazoManual(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($pasta, ['tipo' => 'audiencia', 'titulo' => 'Instrução', 'data' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d'), 'responsavel' => '']);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $linha = $c->filter('[data-trilho="prazos"] .ps-prazo[data-prazo-manual]');
        self::assertCount(1, $linha);
        self::assertSame('Audiência · Instrução', trim($linha->filter('.ps-linha-titulo')->text()));
        self::assertStringContainsString('Sem responsável · ', $linha->filter('.ps-linha-meta')->text());
        self::assertStringContainsString(' · Manual', $linha->filter('.ps-linha-meta')->text());
        self::assertSame('Faltam 2 dias', trim($linha->filter('.ps-selo')->text()));
        self::assertCount(1, $linha->filter('.js-prazo-cumprir'));
        self::assertCount(1, $linha->filter('.js-prazo-editar'));
        self::assertCount(1, $linha->filter('.js-prazo-excluir'));
        self::assertCount(1, $c->filter('[data-trilho="prazos"] .js-prazo-novo'));
        self::assertCount(1, $c->filter('#psPrazoJanela .js-pz-tipo[data-tipo="audiencia"]'));
    }

    #[TestDox('editar troca os dados e registra "Prazo editado"; cumprir tira da lista e registra; excluir apaga e registra')]
    public function testEditarCumprirExcluir(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'Contestação', 'data' => '2030-01-10']);
        $id = $this->resposta()['id'];

        $this->salvar($pasta, ['prazo_id' => (string) $id, 'tipo' => 'prazo', 'titulo' => 'Contestação final', 'data' => '2030-01-12']);
        self::assertResponseIsSuccessful();
        $this->post('/pasta/' . $pasta->getId() . '/prazos/' . $id . '/cumprir');
        self::assertSame('Prazo marcado como cumprido', $this->resposta()['aviso']);
        $this->em()->clear();
        $prazo = $this->em()->find(PastaPrazo::class, $id);
        self::assertSame('Contestação final', $prazo->getTitulo());
        self::assertTrue($prazo->estaCumprido());

        $this->post('/pasta/' . $pasta->getId() . '/prazos/' . $id . '/excluir');
        self::assertSame('Prazo excluído', $this->resposta()['aviso']);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT id FROM pasta_prazo WHERE id = ?', [$id]) ?: null);
        $textos = $this->em()->getConnection()->fetchFirstColumn("SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'prazo' ORDER BY id", [$pasta->getId()]);
        self::assertCount(4, $textos);
        self::assertStringContainsString('Prazo editado: Contestação final', $textos[1]);
        self::assertStringContainsString('Prazo cumprido: Contestação final', $textos[2]);
        self::assertStringContainsString('Prazo excluído: Contestação final', $textos[3]);
    }

    #[TestDox('sem descrição ou sem data, recusa com o texto do desenho')]
    public function testSemDescricaoOuData(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => '  ', 'data' => '2030-01-10']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe a descrição e a data', $this->resposta()['erro']);
        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'X', 'data' => '']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('sem o token, recusa')]
    public function testSemToken(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/prazos', ['_token' => 'errado', 'titulo' => 'X', 'data' => '2030-01-01']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('pasta de outro escritório não é encontrada e nada é gravado')]
    public function testOutroEscritorio(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $dele = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->salvar($dele, ['tipo' => 'prazo', 'titulo' => 'Invasor', 'data' => '2030-01-01']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_prazo WHERE pasta_id = ?', [$dele->getId()]));
    }

    #[TestDox('prazo de outra pasta não é cumprido pela rota desta')]
    public function testPrazoDeOutraPasta(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $a = $this->criarPasta($tenant);
        $b = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($b, ['tipo' => 'prazo', 'titulo' => 'Da B', 'data' => '2030-01-01']);
        $idB = $this->resposta()['id'];

        $this->post('/pasta/' . $a->getId() . '/prazos/' . $idB . '/cumprir');

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT cumprido_em FROM pasta_prazo WHERE id = ?', [$idB]));
    }

    #[TestDox('quem só vê a pasta não registra prazo')]
    public function testSoVeNaoRegistra(): void
    {
        [, $tenant] = $this->criarAdmin();
        $leitor = $this->criarLeitor($tenant);
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $leitor, $tenant);

        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'X', 'data' => '2030-01-01']);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Lista: "Audiência" mostra a pasta com audiência futura; cumprida ou passada fica de fora; "Com prazo" pega o prazo manual')]
    public function testFiltrosDaLista(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $futura = $this->criarPasta($tenant, 'AUDFUT');
        $passada = $this->criarPasta($tenant, 'AUDPAS');
        $cumprida = $this->criarPasta($tenant, 'AUDCUM');
        $soPrazo = $this->criarPasta($tenant, 'PRZMAN');
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($futura, ['tipo' => 'audiencia', 'titulo' => 'A', 'data' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')]);
        $this->salvar($passada, ['tipo' => 'audiencia', 'titulo' => 'B', 'data' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d')]);
        $this->salvar($cumprida, ['tipo' => 'audiencia', 'titulo' => 'C', 'data' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')]);
        $this->post('/pasta/' . $cumprida->getId() . '/prazos/' . $this->resposta()['id'] . '/cumprir');
        $this->salvar($soPrazo, ['tipo' => 'prazo', 'titulo' => 'D', 'data' => (new \DateTimeImmutable('+1 day'))->format('Y-m-d')]);

        $aud = $this->painel('?expediente=aud');
        self::assertStringContainsString($futura->getNup(), $aud);
        self::assertStringNotContainsString($passada->getNup(), $aud);
        self::assertStringNotContainsString($cumprida->getNup(), $aud);
        self::assertStringNotContainsString($soPrazo->getNup(), $aud);

        $prazo = $this->painel('?expediente=prazo');
        self::assertStringContainsString($soPrazo->getNup(), $prazo);
        self::assertStringContainsString($futura->getNup(), $prazo, 'audiência também é prazo em aberto');
    }

    #[TestDox('cartão do sistema no Registro não se edita nem se exclui, nem pelo autor')]
    public function testCartaoDoSistemaSemEdicao(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'X', 'data' => '2030-01-01']);
        $cartao = $this->em()->getRepository(PastaMensagem::class)->findOneBy(['pasta' => $pasta, 'sistema' => 'prazo']);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $art = $c->filter('#pasta-msg-' . $cartao->getId());
        self::assertCount(1, $art);
        self::assertStringContainsString('ps-anotacao--sistema', (string) $art->attr('class'));
        self::assertCount(0, $art->filter('.btn-editar-msg-pasta, .btn-excluir-msg-pasta, .btn-responder-msg-pasta'));
    }

    #[TestDox('cartão do sistema: o servidor recusa editar, excluir e responder, mesmo vindo do autor')]
    public function testCartaoDoSistemaRecusadoNoServidor(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'Original', 'data' => '2030-01-01']);
        $id = (int) $this->em()->getConnection()->fetchOne("SELECT id FROM pasta_mensagem WHERE pasta_id = ? AND sistema = 'prazo'", [$pasta->getId()]);
        $base = '/pasta/' . $pasta->getId() . '/mensagem';

        $this->client->xmlHttpRequest('POST', $base . '/' . $id . '/editar', ['_token' => 'TOKEN_pasta_mensagem_editar_' . $id, 'conteudo' => 'Adulterado']);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->client->xmlHttpRequest('POST', $base . '/' . $id . '/excluir', ['_token' => 'TOKEN_pasta_mensagem_excluir_' . $id]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());
        $this->client->xmlHttpRequest('POST', $base, ['_token' => 'TOKEN_pasta_mensagem_' . $pasta->getId(), 'conteudo' => 'Resposta', 'resposta_a' => (string) $id]);
        self::assertGreaterThanOrEqual(400, $this->client->getResponse()->getStatusCode());

        $conn = $this->em()->getConnection();
        self::assertStringContainsString('Original', (string) $conn->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE id = ?', [$id]));
        self::assertSame(0, (int) $conn->fetchOne('SELECT COUNT(*) FROM pasta_mensagem WHERE resposta_a_id = ?', [$id]));
    }

    #[TestDox('prazo já cumprido não se edita pelo servidor')]
    public function testCumpridoNaoSeEdita(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->salvar($pasta, ['tipo' => 'prazo', 'titulo' => 'X', 'data' => '2030-01-01']);
        $id = $this->resposta()['id'];
        $this->post('/pasta/' . $pasta->getId() . '/prazos/' . $id . '/cumprir');

        $this->salvar($pasta, ['prazo_id' => (string) $id, 'tipo' => 'prazo', 'titulo' => 'Mudado', 'data' => '2030-01-02']);

        self::assertResponseStatusCodeSame(422);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param array<string, string> $dados */
    private function salvar(Pasta $pasta, array $dados): void
    {
        $this->post('/pasta/' . $pasta->getId() . '/prazos', $dados + ['hora' => '', 'responsavel' => '']);
    }

    /** @param array<string, string> $dados */
    private function post(string $url, array $dados = []): void
    {
        $id = (int) preg_replace('#^/pasta/(\d+)/.*$#', '$1', $url);
        $this->client->xmlHttpRequest('POST', $url, $dados + ['_token' => 'TOKEN_' . PastaPrazoController::idDoToken($id)]);
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function painel(string $query): string
    {
        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral' . $query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Prazo ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Prazo');
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

    /** Vê pastas e o Expediente, mas não edita. */
    private function criarLeitor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('Leitor Prazo');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $this->em()->persist($role);
        foreach (['modules.pastas.view', 'resources.pasta.view'] as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $v = new TenantRolePermission();
            $v->setTenantRole($role);
            $v->setPermission($perm);
            $this->em()->persist($v);
            $role->getTenantRolePermissions()->add($v);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('prazo_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $rotulo = ''): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('PZ' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

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
}
