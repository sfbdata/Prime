<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\SeccionaisOabController;
use App\Master\Entity\SeccionalOab;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Seccionais da OAB pelo HTTP (spec docs/specs/master-seccionais-oab.md): só o Master, UF entre as 27, CSRF fixo
 * `master_seccionais` no header, `{mensagem}` e `{erro}`, auditoria de plataforma. As 27 linhas vêm da carga da
 * migration Version20261020148400.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 de quem não é Master
 * vem da regra, não do token. O CSRF inválido tem teste próprio.
 */
#[CoversClass(SeccionaisOabController::class)]
final class SeccionaisOabControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private User $master;
    private User $comum;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório das seccionais ' . uniqid()])->_real();
        $this->master = UserFactory::createOne(['fullName' => 'Master das Seccionais', 'roles' => ['ROLE_SUPER_ADMIN']])->_real();
        $this->comum = UserFactory::createOne(['fullName' => 'Primário do Escritório', 'roles' => ['ROLE_USER']])->_real();
        $this->vincular($this->master);
        $this->vincular($this->comum);
    }

    #[TestDox('GET: as 27 seccionais em ordem de UF, só UF e nome na carga, visíveis e sem marca, com o token e os moldes das URLs')]
    public function testListaAs27(): void
    {
        $this->entrar($this->master);

        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertCount(27, $json['seccionais']);
        self::assertSame('AC', $json['seccionais'][0]['uf']);
        $sp = array_values(array_filter($json['seccionais'], static fn (array $s): bool => $s['uf'] === 'SP'))[0];
        self::assertSame('OAB Seccional São Paulo', $sp['nome']);
        self::assertNull($sp['endereco'], 'a carga não inventa endereço');
        self::assertNull($sp['telefone']);
        self::assertNull($sp['site']);
        self::assertIsInt($sp['inscricoes']);
        self::assertSame(['uf', 'nome', 'endereco', 'telefone', 'site', 'inscricoes', 'visivel', 'marca', 'subsecoes', 'nota', 'notasCidades'], array_keys($sp), 'desenho 05: sem divulgações nem "5 últimas"');
        self::assertNotEmpty($json['token']);
        self::assertSame('/master/seccionais/XX/sede', $json['urlSede']);
        self::assertSame('/master/seccionais/XX/visibilidade', $json['urlVisibilidade']);
        self::assertSame('/master/seccionais/mostrar-todas', $json['urlMostrarTodas']);
    }

    #[TestDox('quem não é Master: 403 no GET e nos POST (sede, divulgação, visibilidade, mostrar todas), e nada muda')]
    public function testNaoMaster403(): void
    {
        $this->entrar($this->comum);

        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/SP/sede', ['endereco' => 'Rua Invasora']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/SP/divulgacoes', ['status' => 'env', 'cor' => '#e53935']);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/SP/visibilidade', []);
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/mostrar-todas', []);
        self::assertResponseStatusCodeSame(403);

        $sp = $this->seccional('SP');
        self::assertNull($sp->getEndereco());
        self::assertNull($sp->getMarcaStatus());
        self::assertTrue($sp->isVisivel());
    }

    #[TestDox('UF fora das 27 (ou minúscula): 404')]
    public function testUfInvalida404(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/XX/sede', ['endereco' => 'Rua']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Seccional não encontrada.', $this->json()['erro']);
        $this->postar('/master/seccionais/ZZ/divulgacoes', ['status' => 'env', 'cor' => '#e53935']);
        self::assertResponseStatusCodeSame(404);
        $this->postar('/master/seccionais/sp/divulgacoes', ['status' => 'env', 'cor' => '#e53935']);
        self::assertResponseStatusCodeSame(404);
        $this->postar('/master/seccionais/XX/visibilidade', []);
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido: 403 com a mensagem e nada muda')]
    public function testCsrfInvalido(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/SP/sede', ['endereco' => 'Rua'], token: 'errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);
        $this->postar('/master/seccionais/SP/divulgacoes', ['status' => 'env', 'cor' => '#e53935'], token: '');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/SP/visibilidade', [], token: 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/mostrar-todas', [], token: 'errado');
        self::assertResponseStatusCodeSame(403);

        $sp = $this->seccional('SP');
        self::assertNull($sp->getEndereco());
        self::assertNull($sp->getMarcaStatus());
        self::assertTrue($sp->isVisivel());
    }

    #[TestDox('editar sede: 200 com a mensagem, grava, e a auditoria fica sem escritório (plataforma)')]
    public function testEditarSede(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/RJ/sede', ['endereco' => 'Av. Exemplo, 1', 'telefone' => '(21) 0000-0000', 'site' => 'https://sede.exemplo.test']);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Sede da OAB Seccional Rio de Janeiro atualizada.'], $this->json());
        $rj = $this->seccional('RJ');
        self::assertSame('Av. Exemplo, 1', $rj->getEndereco());
        self::assertSame('https://sede.exemplo.test', $rj->getSite());
        self::assertSame($this->master->getId(), $rj->getAtualizadoPor()?->getId());
        $this->assertAuditoriaDePlataforma(SeccionalOab::class);
    }

    #[TestDox('editar sede: http:// é aceito (desenho 05); site sem esquema e endereço longo dão 422 com a mensagem')]
    public function testEditarSede422(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/AL/sede', ['site' => 'http://sede.exemplo.test']);
        self::assertResponseIsSuccessful();
        self::assertSame('http://sede.exemplo.test', $this->seccional('AL')->getSite());
        $this->postar('/master/seccionais/RJ/sede', ['site' => 'sede.exemplo.test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o site completo, começando com http:// ou https://.', $this->json()['erro']);
        $this->postar('/master/seccionais/RJ/sede', ['endereco' => str_repeat('a', 256)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O endereço passa de 255 caracteres.', $this->json()['erro']);

        self::assertNull($this->seccional('RJ')->getAtualizadoEm());
    }

    #[TestDox('registrar divulgação: 200 com a mensagem, grava status, cor e data na seccional, sai como marca no GET e a auditoria fica sem escritório')]
    public function testRegistrarDivulgacao(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/MG/divulgacoes', ['status' => 'cli', 'cor' => '#43A047', 'data' => '2026-10-20']);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Divulgação registrada na OAB Seccional Minas Gerais: Parceria firmada.'], $this->json());
        $this->assertAuditoriaDePlataforma(SeccionalOab::class);

        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);
        $mg = $this->daUf('MG');
        self::assertSame(['status' => 'cli', 'rotulo' => 'Parceria firmada', 'cor' => '#43a047', 'data' => '2026-10-20'], $mg['marca']);
    }

    #[TestDox('registrar divulgação sem data: a data é hoje')]
    public function testRegistrarDivulgacaoSemData(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/BA/divulgacoes', ['status' => 'env', 'cor' => '#e53935']);

        self::assertResponseIsSuccessful();
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $this->seccional('BA')->getMarcaEm()?->format('Y-m-d'));
    }

    #[TestDox('registrar divulgação: status desconhecido, cor inválida, data no futuro e corpo vazio dão 422 e nada muda')]
    public function testRegistrarDivulgacao422(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/MG/divulgacoes', ['status' => 'material', 'cor' => '#e53935']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha o status da divulgação.', $this->json()['erro']);
        $this->postar('/master/seccionais/MG/divulgacoes', ['status' => 'env', 'cor' => 'vermelho']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha a cor da marca.', $this->json()['erro']);
        $this->postar('/master/seccionais/MG/divulgacoes', ['status' => 'env', 'cor' => '#e53935', 'data' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A data da divulgação não pode ser no futuro.', $this->json()['erro']);
        $this->client->request('POST', '/master/seccionais/MG/divulgacoes', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_seccionais'], content: 'não é json');
        self::assertResponseStatusCodeSame(422);

        self::assertNull($this->seccional('MG')->getMarcaStatus());
    }

    #[TestDox('clique direito: alterna a visibilidade da seccional (oculta, depois visível) com a mensagem, e o GET reflete')]
    public function testAlternarVisibilidade(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/GO/visibilidade', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'OAB Seccional Goiás oculta no mapa.'], $this->json());
        self::assertFalse($this->seccional('GO')->isVisivel());
        $this->client->request('GET', '/master/dados/seccionais', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertFalse($this->daUf('GO')['visivel']);

        $this->postar('/master/seccionais/GO/visibilidade', []);
        self::assertSame(['mensagem' => 'OAB Seccional Goiás aparece no mapa.'], $this->json());
        self::assertTrue($this->seccional('GO')->isVisivel());
        $this->assertAuditoriaDePlataforma(SeccionalOab::class);
    }

    #[TestDox('"Mostrar todas": limpa o filtro, todas as 27 voltam a aparecer')]
    public function testMostrarTodas(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/GO/visibilidade', []);
        $this->postar('/master/seccionais/TO/visibilidade', []);
        self::assertFalse($this->seccional('TO')->isVisivel());

        $this->postar('/master/seccionais/mostrar-todas', []);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Todas as seccionais aparecem no mapa.'], $this->json());
        self::assertSame(0, $this->em->getRepository(SeccionalOab::class)->count(['visivel' => false]));
    }

    #[TestDox('token válido de OUTRA ação (master_fontes): 403 nas duas escritas e nada é gravado')]
    public function testTokenDeOutraAcao(): void
    {
        $this->entrar($this->master);

        $this->postar('/master/seccionais/SP/sede', ['endereco' => 'Rua'], token: 'TOKEN_master_fontes');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/seccionais/SP/divulgacoes', ['status' => 'env', 'cor' => '#e53935'], token: 'TOKEN_master_fontes');
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->seccional('SP')->getAtualizadoEm());
        self::assertNull($this->seccional('SP')->getMarcaStatus());
    }

    #[TestDox('trilha "Segurança e auditoria": sede alterada e divulgação registrada (marca na seccional) aparecem como "Plataforma (sem conta)"')]
    public function testTrilhaDoMaster(): void
    {
        $this->entrar($this->master);
        $this->postar('/master/seccionais/PE/sede', ['endereco' => 'Rua da Sede, 1', 'site' => 'https://sede.exemplo.test']);
        self::assertResponseIsSuccessful();
        $this->postar('/master/seccionais/PE/divulgacoes', ['status' => 'vis', 'cor' => '#1e88e5']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/master/dados/auditoria', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $registros = $this->json()['registros'];

        $sede = self::registro($registros, 'Campos alterados: endereço, site');
        self::assertSame('Sede da seccional alterada', $sede['acao']);
        self::assertSame('Plataforma (sem conta)', $sede['conta']);
        self::assertNull($sede['contaId']);
        self::assertSame('Master das Seccionais', $sede['quem']);
        $divulgacao = self::registro($registros, 'Campos alterados: cor da marca, status da divulgação, data da divulgação');
        self::assertSame('Plataforma (sem conta)', $divulgacao['conta']);
        self::assertNull($divulgacao['contaId']);
    }

    /**
     * @param list<array<string, mixed>> $registros
     *
     * @return array<string, mixed>
     */
    private static function registro(array $registros, string $detalhe): array
    {
        $achados = array_values(array_filter($registros, static fn (array $r): bool => $r['detalhe'] === $detalhe));
        self::assertNotEmpty($achados, 'a trilha mostra "' . $detalhe . '"');

        return $achados[0];
    }

    private function assertAuditoriaDePlataforma(string $classe): void
    {
        $linhas = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => $classe]);
        self::assertNotEmpty($linhas, 'a gravação entra no audit_log');
        foreach ($linhas as $linha) {
            self::assertNull($linha->getTenantId(), 'ato de plataforma: sem escritório');
        }
    }

    private function seccional(string $uf): SeccionalOab
    {
        $this->em->clear();
        $s = $this->em->getRepository(SeccionalOab::class)->findOneBy(['uf' => $uf]);
        self::assertInstanceOf(SeccionalOab::class, $s);

        return $s;
    }

    /** @return array<string, mixed> a linha da UF no último GET */
    private function daUf(string $uf): array
    {
        return array_values(array_filter($this->json()['seccionais'], static fn (array $s): bool => $s['uf'] === $uf))[0];
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = 'TOKEN_master_seccionais'): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    private function vincular(User $user): void
    {
        $perfil = (new TenantRole())->setTenant($this->escritorio)->setName('Administrador do Escritório ' . uniqid())->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function instalarCsrfFixo(): void
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
