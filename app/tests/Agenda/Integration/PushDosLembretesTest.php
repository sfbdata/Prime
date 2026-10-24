<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Integration;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Entity\Evento;
use App\Agenda\Entity\InscricaoDePush;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\MessageHandler\VarrerLembretesDePushHandler;
use App\Agenda\Repository\PushEnviadoRepository;
use App\Agenda\Repository\VarreduraDePushRepository;
use App\Agenda\UseCase\VarrerLembretesDePushUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Agenda\EnviadorDePushFalso;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Push dos lembretes no banco de verdade (entrega 5b): uma volta por janela (a marca do banco) e o envio
 * idempotente pela chave do lembrete, mesmo com duas varreduras ao mesmo tempo.
 */
#[CoversClass(VarrerLembretesDePushUseCase::class)]
#[CoversClass(VarrerLembretesDePushHandler::class)]
#[CoversClass(PushEnviadoRepository::class)]
#[CoversClass(VarreduraDePushRepository::class)]
final class PushDosLembretesTest extends KernelTestCase
{
    #[TestDox('Duas varreduras do mesmo minuto (cadeia duplicada) mandam cada lembrete uma vez só: a reserva no banco barra a segunda')]
    public function testDuasVarredurasNaoDuplicam(): void
    {
        $falso = new EnviadorDePushFalso();
        static::getContainer()->set(EnviadorDePush::class, $falso);
        [$eu] = $this->cenario('2 navegadores');
        $agora = new \DateTimeImmutable('+30 minutes');

        $sut = static::getContainer()->get(VarrerLembretesDePushUseCase::class);
        $primeira = $sut->executar($agora);
        $segunda = $sut->executar($agora);

        self::assertSame(2, $primeira, 'um aviso para cada navegador da pessoa');
        self::assertSame(0, $segunda, 'a segunda varredura não repete');
        self::assertCount(2, $falso->enviados);
        self::assertSame('BlueJus · Audiência importante', $falso->enviados[0]['conteudo']['titulo']);
        self::assertStringStartsWith('/agenda#ev=ev-', $falso->enviados[0]['conteudo']['url']);
        self::assertFalse(static::getContainer()->get(PushEnviadoRepository::class)->reservar($eu[1], $eu[0], $falso->enviados[0]['conteudo']['tag'], new \DateTimeImmutable()), 'a chave já está reservada no banco');
    }

    #[TestDox('Quem dispara é o Scheduler: o handler não se reagenda, e uma volta repetida dentro da janela de 30 s não varre de novo')]
    public function testUmaVoltaPorJanela(): void
    {
        static::getContainer()->set(EnviadorDePush::class, new EnviadorDePushFalso());
        $conexao = $this->em()->getConnection();
        $conexao->executeStatement('UPDATE agenda_push_varredura SET ultima_em = NULL WHERE id = 1');
        $handler = static::getContainer()->get(VarrerLembretesDePushHandler::class);
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');
        $fila->reset();
        $marca = static fn (): string => (string) $conexao->fetchOne('SELECT ultima_em FROM agenda_push_varredura WHERE id = 1');

        $handler(new VarrerLembretesDePush());
        self::assertNotSame('', $marca(), 'a primeira volta reivindica a vez');
        $dentro = (new \DateTimeImmutable('-' . (VarreduraDePushRepository::VEZ_SEGUNDOS - 10) . ' seconds'))->format('Y-m-d H:i:s');
        $conexao->executeStatement('UPDATE agenda_push_varredura SET ultima_em = ? WHERE id = 1', [$dentro]);
        $handler(new VarrerLembretesDePush());
        self::assertSame($dentro, $marca(), 'dentro da janela, a segunda volta não reivindica e não varre');

        $fora = (new \DateTimeImmutable('-' . (VarreduraDePushRepository::VEZ_SEGUNDOS + 10) . ' seconds'))->format('Y-m-d H:i:s');
        $conexao->executeStatement('UPDATE agenda_push_varredura SET ultima_em = ? WHERE id = 1', [$fora]);
        $handler(new VarrerLembretesDePush());
        self::assertGreaterThan($fora, $marca(), 'passada a janela, a volta seguinte varre');
        self::assertCount(0, $fila->getSent(), 'nenhuma volta se reagenda: o schedule cadeias é quem dispara');
    }

    #[TestDox('Sem chaves VAPID o push fica desligado: nada é enviado e a cadeia não anda')]
    public function testDesligado(): void
    {
        static::getContainer()->set(EnviadorDePush::class, new EnviadorDePushFalso(false));
        $this->cenario('1 navegador');
        $this->em()->getConnection()->executeStatement('UPDATE agenda_push_varredura SET ultima_em = NULL WHERE id = 1');
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        self::assertSame(0, static::getContainer()->get(VarrerLembretesDePushUseCase::class)->executar(new \DateTimeImmutable('+30 minutes')));
        static::getContainer()->get(VarrerLembretesDePushHandler::class)(new VarrerLembretesDePush());
        self::assertCount(0, $fila->getSent(), 'desligado, o handler não despacha nada');
    }

    #[TestDox('Escritório desativado não recebe push')]
    public function testEscritorioInativo(): void
    {
        static::getContainer()->set(EnviadorDePush::class, new EnviadorDePushFalso());
        [[, $tenant]] = $this->cenario('1 navegador');
        $tenant->setIsActive(false);
        $this->em()->flush();

        self::assertSame(0, static::getContainer()->get(VarrerLembretesDePushUseCase::class)->executar(new \DateTimeImmutable('+30 minutes')));
    }

    #[TestDox('Inscrição que o navegador cancelou é apagada depois do envio')]
    public function testInscricaoVencidaApagada(): void
    {
        static::getContainer()->set(EnviadorDePush::class, new EnviadorDePushFalso(true, ['https://push.exemplo/1']));
        $this->cenario('1 navegador');

        static::getContainer()->get(VarrerLembretesDePushUseCase::class)->executar(new \DateTimeImmutable('+30 minutes'));

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM agenda_push_inscricao WHERE endpoint = 'https://push.exemplo/1'"));
    }

    /** @return array{array{User, Tenant}} pessoa com uma audiência daqui a 1 h (lembrete 60 min) e N navegadores */
    private function cenario(string $navegadores): array
    {
        $em = $this->em();
        $tenant = (new Tenant())->setName('Tenant PUSH ' . uniqid());
        $em->persist($tenant);
        $eu = new User();
        $eu->setEmail('push_' . uniqid() . '@test.com');
        $eu->setFullName('Pessoa Push');
        $eu->setRoles(['ROLE_USER']);
        $eu->setIsActive(true);
        $eu->setPassword('x');
        $em->persist($eu);
        $role = (new TenantRole())->setTenant($tenant)->setName('Gestor ' . uniqid())->setIsSystem(true);
        $em->persist($role);
        $ut = new UserTenant($eu, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $inicio = (new \DateTimeImmutable('+1 hour'))->setTime((int) (new \DateTimeImmutable('+1 hour'))->format('H'), (int) (new \DateTimeImmutable('+1 hour'))->format('i'));
        $evento = (new Evento())->setTitulo('Audiência importante')->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))->setCriador($eu)->setTenant($tenant)->setLembreteMinutos(60);
        $em->persist($evento);
        $n = (int) $navegadores;
        for ($i = 1; $i <= $n; ++$i) {
            $em->persist(new InscricaoDePush($tenant, $eu, 'https://push.exemplo/' . $i . ('1 navegador' === $navegadores ? '' : '-' . uniqid()), 'BAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8gISIjJCUmJygpKissLS4vMDEyMzQ1Njc4OTo7PD0-P0A', 'AAECAwQFBgcICQoLDA0ODw', 'America/Sao_Paulo'));
        }
        $em->flush();

        return [[$eu, $tenant]];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
