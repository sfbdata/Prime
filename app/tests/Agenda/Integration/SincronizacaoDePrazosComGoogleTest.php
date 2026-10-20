<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Integration;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Service\LevarPrazosAoGoogle;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\Service\PrazoParaOGoogle;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use App\Tests\Factory\Pasta\PastaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Prazos e audiências das pastas na agenda do escritório no Google (spec agenda-google §7), de ponta a ponta contra
 * o Google falso e o banco de teste.
 */
#[CoversClass(LevarPrazosAoGoogle::class)]
#[CoversClass(PrazoParaOGoogle::class)]
final class SincronizacaoDePrazosComGoogleTest extends KernelTestCase
{
    private GoogleFalso $google;

    protected function setUp(): void
    {
        $this->google = new GoogleFalso();
        static::getContainer()->set(GoogleAgendaApi::class, $this->google->api());
    }

    #[TestDox('Vai a quem tem "Ver prazos na agenda", com o título da grade e a hora; não vai a quem não tem; pasta oculta não vai')]
    public function testQuemRecebe(): void
    {
        $tenant = $this->escritorio();
        $comPrazos = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $semPrazos = $this->pessoa($tenant, ['modules.agenda.view', 'modules.pastas.view']);
        $dono = $this->pessoa($tenant, [], true);
        $audiencia = $this->prazo($tenant, 'GP-1', PastaPrazo::TIPO_AUDIENCIA, 'Conciliação', '+3 days', '14:30');
        $oculta = $this->prazo($tenant, 'GP-2', PastaPrazo::TIPO_PRAZO, 'Réplica', '+3 days', null);
        $this->trancar($oculta->getPasta(), $dono);
        $dele = $this->conectar($comPrazos, $tenant);
        $daOutra = $this->conectar($semPrazos, $tenant);

        $this->sincronizar($dele);
        $this->sincronizar($daOutra);

        $la = $this->eventosNaAgenda($dele);
        self::assertSame(['bjt' . $tenant->getId() . 'p' . $audiencia->getId()], array_keys($la));
        $evento = array_values($la)[0];
        self::assertSame('Audiência · Pasta GP-1', $evento['summary']);
        self::assertSame((new \DateTimeImmutable('+3 days'))->format('Y-m-d') . 'T14:30:00-03:00', $evento['start']['dateTime']);
        self::assertStringContainsString('Para mudar, abra a pasta no BlueJus', $evento['description']);
        self::assertSame([], $this->eventosNaAgenda($daOutra), 'sem a permissão, nada');
    }

    #[TestDox('Só leitura: mexido no Google volta como aqui; apagado lá fica apagado até o prazo mudar aqui; nunca vira compromisso')]
    public function testSoLeitura(): void
    {
        $tenant = $this->escritorio();
        $eu = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $prazo = $this->prazo($tenant, 'GP-3', PastaPrazo::TIPO_PRAZO, 'Contestação', '+5 days', null);
        $conexao = $this->conectar($eu, $tenant);
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $id = 'bjt' . $tenant->getId() . 'p' . $prazo->getId();

        $this->google->paraTrazer[$agenda] = [['summary' => 'Mexi aqui'] + $this->google->eventos[$agenda][$id]];
        $this->sincronizar($conexao);
        $this->sincronizar($conexao);
        self::assertSame('Contestação · Pasta GP-3', $this->google->eventos[$agenda][$id]['summary']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenant->getId()]), 'nunca vira compromisso');

        unset($this->google->eventos[$agenda][$id]);
        $this->google->paraTrazer[$agenda] = [['id' => $id, 'status' => 'cancelled']];
        $this->sincronizar($conexao);
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'apagado lá, não volta sem mudança');

        $this->em()->find(PastaPrazo::class, $prazo->getId())?->definir(PastaPrazo::TIPO_PRAZO, 'Contestação', new \DateTimeImmutable('+6 days'), null, 'Maria');
        $this->em()->flush();
        $this->sincronizar($conexao);
        self::assertArrayHasKey($id, $this->google->eventos[$agenda], 'mudou aqui, volta');
        self::assertStringContainsString('Responsável: Maria', $this->google->eventos[$agenda][$id]['description']);
    }

    #[TestDox('Sai do Google quando é cumprido, excluído ou quando a pessoa perde a permissão')]
    public function testSaiDoGoogle(): void
    {
        $tenant = $this->escritorio();
        $eu = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $cumprido = $this->prazo($tenant, 'GP-4', PastaPrazo::TIPO_PRAZO, 'Recurso', '+2 days', null);
        $excluido = $this->prazo($tenant, 'GP-5', PastaPrazo::TIPO_PRAZO, 'Réplica', '+2 days', null);
        $fica = $this->prazo($tenant, 'GP-6', PastaPrazo::TIPO_AUDIENCIA, 'Instrução', '+2 days', '09:00');
        $conexao = $this->conectar($eu, $tenant);
        $this->sincronizar($conexao);
        self::assertCount(3, $this->eventosNaAgenda($conexao));

        $this->em()->find(PastaPrazo::class, $cumprido->getId())?->cumprir($this->em()->find(User::class, $eu->getId()));
        $this->em()->remove($this->em()->find(PastaPrazo::class, $excluido->getId()));
        $this->em()->flush();
        $r = $this->sincronizar($conexao);

        self::assertSame(2, $r['apagados']);
        self::assertSame(['bjt' . $tenant->getId() . 'p' . $fica->getId()], array_keys($this->eventosNaAgenda($conexao)));

        $this->em()->getConnection()->executeStatement(
            'DELETE FROM tenant_role_permission WHERE permission_id IN (SELECT id FROM permission WHERE code = :c)',
            ['c' => PermissoesDaAgenda::VER_PRAZOS],
        );
        $this->sincronizar($conexao);
        self::assertSame([], $this->eventosNaAgenda($conexao));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_prazo WHERE conexao_id = :c', ['c' => $conexao->getId()]));
    }

    #[TestDox('Um escritório não vê o prazo do outro; um id de prazo de outro escritório vindo do Google não faz nada')]
    public function testDoisEscritorios(): void
    {
        $meu = $this->escritorio();
        $outro = $this->escritorio();
        $eu = $this->pessoa($meu, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $doMeu = $this->prazo($meu, 'GP-8', PastaPrazo::TIPO_PRAZO, 'Apelação', '+2 days', null);
        $doOutro = $this->prazo($outro, 'GP-9', PastaPrazo::TIPO_PRAZO, 'Embargos', '+2 days', null);
        $conexao = $this->conectar($eu, $meu);
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $deFora = 'bjt' . $outro->getId() . 'p' . $doOutro->getId();
        $this->google->paraTrazer[$agenda] = [['id' => $deFora, 'summary' => 'Embargos', 'start' => ['date' => '2030-01-01'], 'end' => ['date' => '2030-01-02']]];

        $r = $this->sincronizar($conexao);

        self::assertSame(['bjt' . $meu->getId() . 'p' . $doMeu->getId()], array_keys($this->eventosNaAgenda($conexao)));
        self::assertSame(0, $r['trazidos']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_prazo WHERE conexao_id = :c', ['c' => $conexao->getId()]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id IN (:a, :b)', ['a' => $meu->getId(), 'b' => $outro->getId()]));
    }

    #[TestDox('Pasta trancada depois do envio: o prazo sai da agenda de quem não a vê mais')]
    public function testTrancadaDepois(): void
    {
        $tenant = $this->escritorio();
        $eu = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $dono = $this->pessoa($tenant, [], true);
        $prazo = $this->prazo($tenant, 'GP-10', PastaPrazo::TIPO_PRAZO, 'Réplica', '+2 days', null);
        $conexao = $this->conectar($eu, $tenant);
        $this->sincronizar($conexao);
        self::assertCount(1, $this->eventosNaAgenda($conexao));

        $this->trancar($this->em()->find(PastaPrazo::class, $prazo->getId())->getPasta(), $this->em()->find(User::class, $dono->getId()));
        $r = $this->sincronizar($conexao);

        self::assertSame(1, $r['apagados']);
        self::assertSame([], $this->eventosNaAgenda($conexao));
    }

    #[TestDox('Apagado no Google e mudado aqui antes da sincronia: a mudança ainda vai')]
    public function testApagadoLaEMudadoAqui(): void
    {
        $tenant = $this->escritorio();
        $eu = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $prazo = $this->prazo($tenant, 'GP-11', PastaPrazo::TIPO_PRAZO, 'Contestação', '+5 days', null);
        $conexao = $this->conectar($eu, $tenant);
        $this->sincronizar($conexao);
        $agenda = $this->agendaDe($conexao);
        $id = 'bjt' . $tenant->getId() . 'p' . $prazo->getId();
        unset($this->google->eventos[$agenda][$id]);
        $this->google->paraTrazer[$agenda] = [['id' => $id, 'status' => 'cancelled']];
        $this->em()->find(PastaPrazo::class, $prazo->getId())?->definir(PastaPrazo::TIPO_PRAZO, 'Contestação', new \DateTimeImmutable('+7 days'), null, null);
        $this->em()->flush();

        $this->sincronizar($conexao);

        self::assertArrayHasKey($id, $this->google->eventos[$agenda]);
    }

    #[TestDox('A agenda do escritório apagada no Google é recriada e os prazos vão de novo')]
    public function testAgendaNova(): void
    {
        $tenant = $this->escritorio();
        $eu = $this->pessoa($tenant, ['modules.agenda.view', PermissoesDaAgenda::VER_PRAZOS]);
        $this->prazo($tenant, 'GP-7', PastaPrazo::TIPO_PRAZO, 'Contrarrazões', '+4 days', null);
        $conexao = $this->conectar($eu, $tenant);
        $this->sincronizar($conexao);
        $this->google->apagarAgendaNoGoogle($this->agendaDe($conexao));

        $this->sincronizar($conexao);
        $this->sincronizar($conexao);

        self::assertCount(1, $this->eventosNaAgenda($conexao));
    }

    /** @return array<string, int> */
    private function sincronizar(ConexaoGoogleAgenda $conexao): array
    {
        $r = static::getContainer()->get(SincronizarGoogleAgendaUseCase::class)->executar((int) $conexao->getId(), new \DateTimeImmutable());
        $this->em()->clear();

        return $r ?? ['ocupada' => true];
    }

    /** @return array<string, array<string, mixed>> */
    private function eventosNaAgenda(ConexaoGoogleAgenda $conexao): array
    {
        return $this->google->eventos[$this->agendaDe($conexao)] ?? [];
    }

    private function agendaDe(ConexaoGoogleAgenda $conexao): string
    {
        return (string) $this->em()->find(ConexaoGoogleAgenda::class, $conexao->getId())?->getCalendarioId();
    }

    private function escritorio(): Tenant
    {
        $tenant = (new Tenant())->setName('Tenant PRAZO GOOGLE ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function conectar(User $pessoa, Tenant $tenant): ConexaoGoogleAgenda
    {
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO');
        $conexao = new ConexaoGoogleAgenda($this->em()->find(Tenant::class, $tenant->getId()), $this->em()->find(User::class, $pessoa->getId()), $cifrado, 'p' . uniqid() . '@gmail.com', 'America/Sao_Paulo');
        $this->em()->persist($conexao);
        $this->em()->flush();

        return $conexao;
    }

    private function prazo(Tenant $tenant, string $nup, string $tipo, string $titulo, string $quando, ?string $hora): PastaPrazo
    {
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => $nup])->_real();
        $prazo = new PastaPrazo($this->em()->find(Tenant::class, $tenant->getId()), $pasta, null);
        $prazo->definir($tipo, $titulo, new \DateTimeImmutable($quando), $hora, null);
        $this->em()->persist($prazo);
        $this->em()->flush();

        return $prazo;
    }

    private function trancar(Pasta $pasta, User $por): void
    {
        $this->em()->persist(new CadeadoDaPasta($pasta->getTenant(), $pasta, ModoDoCadeado::Todos, $por, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    /** @param list<string> $permissoes */
    private function pessoa(Tenant $tenant, array $permissoes, bool $sistema = false): User
    {
        $u = (new User())->setEmail('gp_' . uniqid() . '@test.com')->setFullName('Pessoa Prazo')->setRoles(['ROLE_USER'])->setIsActive(true)->setPassword('x');
        $this->em()->persist($u);
        $role = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem($sistema);
        $this->em()->persist($role);
        foreach ($permissoes as $codigo) {
            $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]) ?? (new Permission())->setCode($codigo)->setDescription($codigo)->setGroup(explode('.', $codigo)[0]);
            $this->em()->persist($permissao);
            $concessao = new TenantRolePermission();
            $concessao->setTenantRole($role);
            $concessao->setPermission($permissao);
            $this->em()->persist($concessao);
            $role->getTenantRolePermissions()->add($concessao);
        }
        $vinculo = new UserTenant($u, $tenant);
        $vinculo->setTenantRole($role);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $u;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
