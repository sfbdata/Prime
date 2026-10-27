<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\DesempenhoMasterDadosController;
use App\Master\Entity\EventoDeDesempenho;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Enum\GravidadeDeIncidente;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Master\Enum\TipoDeRegistroDeBackup;
use App\Master\Repository\EventoDeDesempenhoRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /master/dados/desempenho (spec master-desempenho PR 1): só o Master, período em 1/7/30 dias, eventos fora
 * da janela não contam. O banco de teste é limpo dos eventos no começo (DAMA desfaz no fim).
 */
#[CoversClass(DesempenhoMasterDadosController::class)]
#[CoversClass(EventoDeDesempenhoRepository::class)]
final class DesempenhoMasterDadosControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getRepository(EventoDeDesempenho::class)->apagarAntesDe();
    }

    #[TestDox('quem não é Master recebe 403')]
    public function testUsuarioComumNaoEntra(): void
    {
        [$user, $conta] = $this->usuarioComConta(['ROLE_USER']);
        $this->logarComTenant($this->client, $user, $conta);

        $this->client->request('GET', '/master/dados/desempenho');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('sem nenhuma medição devolve "semdados" nos medidos, listas vazias e período padrão 7')]
    public function testSemDados(): void
    {
        $dados = $this->consultar();

        self::assertSame(7, $dados['periodo']);
        self::assertSame('semdados', $dados['indicadores']['disponibilidade']['tom']);
        self::assertSame('semdados', $dados['indicadores']['ultimoBackup']['tom']);
        self::assertSame([], $dados['erros']);
        self::assertSame(0, $dados['incidentesAbertos']);
        self::assertSame(['email', 'banco_pix', 'google', 'facebook', 'tribunais', 'ia'], array_column($dados['integracoes'], 'chave'));
    }

    #[TestDox('conta eventos da janela, ignora os de fora, calcula P95 e traz erros, incidentes e backups')]
    public function testComDados(): void
    {
        $agora = new \DateTimeImmutable();
        $this->evento(TipoDeEventoDeDesempenho::Ping, $agora, ok: true, ms: 40);
        $this->evento(TipoDeEventoDeDesempenho::Ping, $agora, ok: false);
        $this->evento(TipoDeEventoDeDesempenho::Ping, $agora->modify('-10 days'), ok: false);
        foreach ([100, 200, 300, 400] as $ms) {
            $this->evento(TipoDeEventoDeDesempenho::Carga, $agora, ms: $ms);
        }
        $erro = $this->evento(TipoDeEventoDeDesempenho::Erro, $agora);
        $erro->descrever('  TypeError: x é indefinido ', 'app.js', '/expediente', null);
        $this->evento(TipoDeEventoDeDesempenho::Login, $agora, ok: false);
        $this->em->persist(new IncidenteDeDesempenho('Fila parada', 'Integração', GravidadeDeIncidente::Alta, $agora));
        $this->em->persist(new RegistroDeBackup(TipoDeRegistroDeBackup::Backup, true, 'ok', $agora->modify('-2 hours')));
        $this->em->flush();

        $d = $this->consultar();
        $i = $d['indicadores'];

        self::assertEquals(50.0, $i['disponibilidade']['percentual'], 'o ping de 10 dias atrás fica de fora do período de 7');
        self::assertSame(400, $i['p95']['ms']);
        self::assertSame(1, $i['erros']['total']);
        self::assertSame(1, $i['loginsFalhos']['falhas']);
        self::assertSame(2, $i['ultimoBackup']['horas']);
        self::assertSame('TypeError: x é indefinido', $d['erros'][0]['mensagem']);
        self::assertSame('/expediente', $d['erros'][0]['pagina']);
        self::assertSame(1, $d['incidentesAbertos']);
        self::assertSame('alta', $d['incidentes'][0]['gravidade']);
        self::assertSame('backup', $d['backups'][0]['tipo']);
    }

    #[TestDox('período de 30 dias inclui o evento antigo; período inválido cai em 7')]
    public function testPeriodo(): void
    {
        $this->evento(TipoDeEventoDeDesempenho::Ping, (new \DateTimeImmutable())->modify('-10 days'), ok: true);
        $this->em->flush();

        self::assertSame(1, $this->consultar(30)['indicadores']['disponibilidade']['verificacoes']);
        $padrao = $this->consultar(5);
        self::assertSame(7, $padrao['periodo']);
        self::assertSame(0, $padrao['indicadores']['disponibilidade']['verificacoes']);
    }

    #[TestDox('apagarAntesDe sem data apaga todos os eventos; com data, só os anteriores')]
    public function testApagar(): void
    {
        $repo = $this->em->getRepository(EventoDeDesempenho::class);
        $agora = new \DateTimeImmutable();
        $this->evento(TipoDeEventoDeDesempenho::Erro, $agora->modify('-100 days'));
        $this->evento(TipoDeEventoDeDesempenho::Erro, $agora);
        $this->em->flush();

        self::assertSame(1, $repo->apagarAntesDe($agora->modify('-90 days')));
        self::assertSame(1, $repo->apagarAntesDe());
    }

    /** @return array<string, mixed> */
    private function consultar(?int $periodo = null): array
    {
        $master = UserFactory::createOne(['fullName' => 'Master', 'roles' => ['ROLE_SUPER_ADMIN'], 'isActive' => true])->_real();
        $conta = $this->contaDe($master);
        $this->logarComTenant($this->client, $master, $conta);

        $this->client->request('GET', '/master/dados/desempenho' . ($periodo !== null ? '?periodo=' . $periodo : ''));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function evento(TipoDeEventoDeDesempenho $tipo, \DateTimeImmutable $em, ?bool $ok = null, ?int $ms = null): EventoDeDesempenho
    {
        $evento = new EventoDeDesempenho($tipo, $em, $ok, $ms);
        $this->em->persist($evento);

        return $evento;
    }

    /**
     * @param list<string> $papeis
     * @return array{User, Tenant}
     */
    private function usuarioComConta(array $papeis): array
    {
        $user = UserFactory::createOne(['fullName' => 'Fulano', 'roles' => $papeis, 'isActive' => true])->_real();

        return [$user, $this->contaDe($user)];
    }

    private function contaDe(User $user): Tenant
    {
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($user);
        $this->em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $conta))->setTenantRole($perfil));
        $this->em->flush();

        return $conta;
    }
}
