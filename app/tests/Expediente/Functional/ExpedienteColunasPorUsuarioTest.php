<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Menu "Colunas da tabela" da Lista do Expediente: o ajuste é de CADA usuário, guardado no servidor por
 * escritório (`preferencia_usuario`, chave `expediente.colunas`). A tabela traz o ajuste em `data-colunas`
 * (`null` = padrão) e a gravação é POST /expediente/colunas com o token da Lista no `X-CSRF-Token`.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteColunasPorUsuarioTest extends JusPrimeWebTestCase
{
    private const SEM_REU = ['nup' => true, 'cliente' => true, 'reu' => false, 'acao' => true, 'prio' => true, 'resp' => true, 'metas' => true, 'marc' => true, 'sit' => true];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    #[TestDox('A grava escondendo uma coluna e o data-colunas dele traz o mapa; B, no mesmo escritório, continua no padrão (null)')]
    public function testAjusteDeUmNaoMudaParaOOutro(): void
    {
        $tenant = $this->criarEscritorio();
        $a = $this->criarUsuario($tenant);
        $b = $this->criarUsuario($tenant);

        $this->entrarComo($a, $tenant);
        self::assertNull($this->colunasDaTabela(), 'sem ajuste, a tabela vem no padrão');
        $this->gravar(['colunas' => self::SEM_REU]);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->resposta());
        self::assertSame(self::SEM_REU, $this->colunasDaTabela());

        $this->entrarComo($b, $tenant);
        self::assertNull($this->colunasDaTabela(), 'o ajuste de A não aparece para B');
    }

    #[TestDox('A em outra sessão (outro navegador) vê o próprio ajuste, inclusive no painel do marcador')]
    public function testAjusteAcompanhaOUsuarioEmOutraSessao(): void
    {
        $tenant = $this->criarEscritorio();
        $a = $this->criarUsuario($tenant);
        $marcador = new Marcador('Colunas ' . uniqid(), $tenant, $a);
        $this->em()->persist($marcador);
        $pasta = new Pasta();
        $pasta->setNup('MARC-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->addMarcador($marcador);
        $this->em()->persist($pasta);
        $this->em()->flush();
        $this->entrarComo($a, $tenant);
        $this->gravar(['colunas' => self::SEM_REU]);
        self::assertResponseIsSuccessful();

        $this->entrarComo($a, $tenant);
        self::assertSame(self::SEM_REU, $this->colunasDaTabela());
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/marcador/' . $marcador->getId() . '/pastas');
        self::assertResponseIsSuccessful();
        self::assertSame(self::SEM_REU, json_decode((string) $crawler->filter('#tabelaPastas')->attr('data-colunas'), true));
    }

    #[TestDox('Restaurar padrão (colunas null) apaga o ajuste: data-colunas volta a null')]
    public function testRestaurarVoltaAoPadrao(): void
    {
        $tenant = $this->criarEscritorio();
        $a = $this->criarUsuario($tenant);
        $this->entrarComo($a, $tenant);
        $this->gravar(['colunas' => self::SEM_REU]);
        self::assertResponseIsSuccessful();

        $this->gravar(['colunas' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->colunasDaTabela());
        self::assertSame(0, $this->linhasDeAjuste($a));
    }

    #[TestDox('token da Lista inválido é recusado (403) e nada é gravado')]
    public function testTokenInvalidoNaoGrava(): void
    {
        $tenant = $this->criarEscritorio();
        $a = $this->criarUsuario($tenant);
        $this->entrarComo($a, $tenant);

        $this->gravar(['colunas' => self::SEM_REU], 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->linhasDeAjuste($a));
        self::assertNull($this->colunasDaTabela());
    }

    #[TestDox('formato inválido (coluna que a tabela não tem, valor não booleano, corpo sem o campo, JSON quebrado) responde 422 com {erro} e não grava')]
    public function testFormatoInvalido(): void
    {
        $tenant = $this->criarEscritorio();
        $a = $this->criarUsuario($tenant);
        $this->entrarComo($a, $tenant);

        foreach ([['colunas' => ['senha' => false]], ['colunas' => ['reu' => 'nao']], ['colunas' => 'reu'], ['outra' => true]] as $corpo) {
            $this->gravar($corpo);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('Formato das colunas inválido.', $this->resposta()['erro'] ?? null);
        }
        $this->gravar('{quebrado');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->linhasDeAjuste($a));
    }

    #[TestDox('A com conta em dois escritórios tem dois ajustes independentes')]
    public function testDoisEscritoriosDoisAjustes(): void
    {
        $um = $this->criarEscritorio();
        $dois = $this->criarEscritorio();
        $a = $this->criarUsuario($um);
        $this->vincular($a, $dois);

        $this->entrarComo($a, $um);
        $this->gravar(['colunas' => self::SEM_REU]);
        self::assertResponseIsSuccessful();

        $this->entrarComo($a, $dois);
        self::assertNull($this->colunasDaTabela(), 'no outro escritório, A começa do padrão');
        $semSit = ['sit' => false] + self::SEM_REU;
        $semSit['reu'] = true;
        $this->gravar(['colunas' => $semSit]);
        self::assertResponseIsSuccessful();
        self::assertSame($semSit, $this->colunasDaTabela());

        $this->entrarComo($a, $um);
        self::assertSame(self::SEM_REU, $this->colunasDaTabela(), 'o ajuste do primeiro escritório não mudou');
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    /** O `data-colunas` da tabela do acervo, decodificado (null = padrão). */
    private function colunasDaTabela(): ?array
    {
        $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
        self::assertResponseIsSuccessful();
        $tabela = $crawler->filter('#tabelaPastas');
        self::assertCount(1, $tabela);
        self::assertNotEmpty($tabela->attr('data-url-colunas'));

        return json_decode((string) $tabela->attr('data-colunas'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** POST do menu de colunas com o token da Lista (lido da tabela, como o JS faz) ou o token dado. */
    private function gravar(array|string $corpo, ?string $token = null): void
    {
        if ($token === null) {
            $crawler = $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');
            $token = (string) $crawler->filter('#tabelaPastas')->attr('data-csrf-lista');
        }
        $this->client->request('POST', '/expediente/colunas', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_X_CSRF_TOKEN'     => $token,
        ], is_string($corpo) ? $corpo : json_encode($corpo, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Nova sessão (outro navegador) logada como o usuário, no escritório dado. */
    private function entrarComo(User $user, Tenant $tenant): void
    {
        $this->client->restart();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    private function linhasDeAjuste(User $user): int
    {
        return (int) $this->em()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM preferencia_usuario WHERE user_id = :u AND chave = 'expediente.colunas'",
            ['u' => $user->getId()],
        );
    }

    private function criarEscritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Colunas ' . uniqid());
        $this->em()->persist($tenant);
        // A tabela (com o data-colunas) só aparece com pasta na lista.
        $pasta = new Pasta();
        $pasta->setNup('COL-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('colunas_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Colunas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->vincular($user, $tenant);

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
