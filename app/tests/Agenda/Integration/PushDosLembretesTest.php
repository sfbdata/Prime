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
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Push dos lembretes no banco de verdade (entrega 5b): a cadeia não duplica e o envio é idempotente pela
 * chave do lembrete, mesmo com duas varreduras ao mesmo tempo.
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

    #[TestDox('A cadeia: só uma mensagem reivindica a vez e agenda a próxima; a outra morre sem se reagendar')]
    public function testCadeiaUnica(): void
    {
        static::getContainer()->set(EnviadorDePush::class, new EnviadorDePushFalso());
        $this->em()->getConnection()->executeStatement('UPDATE agenda_push_varredura SET ultima_em = NULL WHERE id = 1');
        $handler = static::getContainer()->get(VarrerLembretesDePushHandler::class);
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');

        $handler(new VarrerLembretesDePush());
        $handler(new VarrerLembretesDePush());

        $agendadas = $fila->getSent();
        self::assertCount(1, $agendadas, 'só a cadeia que reivindicou a vez se reagenda');
        self::assertSame(60000, $agendadas[0]->last(DelayStamp::class)?->getDelay());
        self::assertFalse(static::getContainer()->get(VarreduraDePushRepository::class)->estaParada(new \DateTimeImmutable()));
        self::assertTrue(static::getContainer()->get(VarreduraDePushRepository::class)->estaParada(new \DateTimeImmutable('+4 minutes')), 'sem volta há mais de 3 min, conta como parada e é religada');
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
        self::assertCount(0, $fila->getSent(), 'desligado, o handler não se reagenda');
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
