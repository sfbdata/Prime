<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Integration;

use App\Agenda\Entity\ConexaoGoogleAgenda;
use App\Agenda\Entity\Evento;
use App\Agenda\Enum\TipoDeCompromisso;
use App\Agenda\Infra\GoogleAgendaApi;
use App\Agenda\Message\SincronizarGoogleDaPessoa;
use App\Agenda\Message\VarrerGoogleAgenda;
use App\Agenda\MessageHandler\VarrerGoogleAgendaHandler;
use App\Agenda\UseCase\EstadoDoGoogleAgendaUseCase;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\Tenant;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Sincronia do Google Agenda de ponta a ponta contra um Google falso (MockHttpClient) e o banco de teste.
 * Spec: docs/specs/agenda-google.md.
 */
#[CoversClass(SincronizarGoogleAgendaUseCase::class)]
#[CoversClass(VarrerGoogleAgendaHandler::class)]
#[CoversClass(\App\Agenda\EventListener\MudancaNaAgendaParaOGoogleListener::class)]
final class SincronizacaoComGoogleTest extends KernelTestCase
{
    private GoogleFalso $google;

    protected function setUp(): void
    {
        $this->google = new GoogleFalso();
        static::getContainer()->set(GoogleAgendaApi::class, $this->google->api());
    }

    #[TestDox('O que nasce no Google entra como "Meus compromissos" e "Só eu"; a segunda sincronia não devolve nada ao Google')]
    public function testTrazDoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->google->paraTrazer = [['id' => 'abc123', 'summary' => 'Dentista', 'start' => ['dateTime' => '2030-03-10T15:00:00-03:00'], 'end' => ['dateTime' => '2030-03-10T16:00:00-03:00']]];

        $r = $this->sincronizar($conexao);
        $evento = $this->em()->getRepository(Evento::class)->findOneBy(['googleId' => 'abc123']);

        self::assertSame(1, $r['trazidos']);
        self::assertNotNull($evento);
        self::assertSame('Dentista', $evento->getTitulo());
        self::assertSame(Evento::VISIBILIDADE_SOMENTE_EU, $evento->getVisibilidade());
        self::assertSame(TipoDeCompromisso::Meus, $evento->getTipo());
        self::assertSame($eu->getId(), $evento->getCriador()?->getId());
        self::assertSame(0, $r['enviados'], 'o que veio do Google não volta para lá');
        self::assertSame(0, $this->sincronizar($conexao)['enviados']);
        self::assertSame('st-2', $this->recarregar($conexao)->getSyncToken());
    }

    #[TestDox('Compromisso criado aqui vai ao Google com id nosso; sem mudança não vai de novo; mudou, vai como atualização')]
    public function testLevaAoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Audiência');

        self::assertSame(1, $this->sincronizar($conexao)['enviados']);
        $id = 'bjt' . $tenant->getId() . 'e' . $evento->getId();
        self::assertSame($id, $this->em()->find(Evento::class, $evento->getId())?->getGoogleId());
        self::assertContains('POST /calendar/v3/calendars/primary/events', $this->google->pedidos);
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'sem mudança, nada vai');

        $evento = $this->em()->find(Evento::class, $evento->getId());
        $evento->setTitulo('Audiência remarcada');
        $this->em()->flush();
        self::assertSame(1, $this->sincronizar($conexao)['enviados']);
        self::assertSame('Audiência remarcada', $this->google->eventos[$id]['summary']);
        self::assertContains('PUT /calendar/v3/calendars/primary/events/' . $id, $this->google->pedidos);
    }

    #[TestDox('Enviar duas vezes (sincronia repetida, marca perdida) não duplica no Google: o 409 vira atualização')]
    public function testInserirDuasVezesNaoDuplica(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Reunião');
        $this->sincronizar($conexao);
        $this->em()->getConnection()->executeStatement('UPDATE evento SET google_id = NULL, google_hash = NULL WHERE id = :id', ['id' => $evento->getId()]);
        $this->em()->clear();

        $this->sincronizar($conexao);

        self::assertCount(1, $this->google->eventos);
        self::assertContains('PUT /calendar/v3/calendars/primary/events/bjt' . $tenant->getId() . 'e' . $evento->getId(), $this->google->pedidos);
    }

    #[TestDox('Cancelado no Google não apaga aqui: o compromisso fica, na situação "cancelado"')]
    public function testCanceladoNoGoogleFica(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Audiência');
        $this->sincronizar($conexao);
        $this->google->paraTrazer = [['id' => $this->recarregarEvento($evento)->getGoogleId(), 'status' => 'cancelled']];

        $this->sincronizar($conexao);

        $evento = $this->recarregarEvento($evento);
        self::assertSame(Evento::STATUS_CANCELADO, $evento->getStatus());
        self::assertSame('Audiência', $evento->getTitulo());
    }

    #[TestDox('Excluído aqui (de qualquer tela) é apagado no Google na sincronia seguinte')]
    public function testExcluidoAquiApagaNoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Prazo pessoal');
        $this->sincronizar($conexao);
        $id = (string) $this->recarregarEvento($evento)->getGoogleId();
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');
        $fila->reset();

        $this->em()->remove($this->recarregarEvento($evento));
        $this->em()->flush();
        $pedida = array_filter($fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof SincronizarGoogleDaPessoa);
        $r = $this->sincronizar($conexao);

        self::assertCount(1, $pedida, 'excluir pede a sincronia da pessoa');
        self::assertSame(1, $r['apagados']);
        self::assertArrayNotHasKey($id, $this->google->eventos);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_exclusao'));
    }

    #[TestDox('Vai só o que a pessoa criou: o compromisso de um colega em que ela participa fica fora')]
    public function testSoOQueEuCriei(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        [$colega] = $this->pessoa($tenant);
        $doColega = $this->compromisso($colega, $tenant, 'Reunião do colega');
        $doColega->getParticipantes()->add($eu);
        $this->em()->flush();

        self::assertSame(0, $this->sincronizar($conexao)['enviados']);
        self::assertSame([], $this->google->eventos);
    }

    #[TestDox('syncToken vencido (410) recomeça do zero; autorização revogada na conta Google volta o painel a "Não conectado"')]
    public function testRecomecoEAutorizacaoRevogada(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $this->google->syncTokenVencido = true;
        $this->google->paraTrazer = [['id' => 'zz1', 'summary' => 'Depois do 410', 'start' => ['date' => '2030-05-01'], 'end' => ['date' => '2030-05-02']]];
        self::assertSame(1, $this->sincronizar($conexao)['trazidos']);

        $this->google->recusarAutorizacao = true;
        $this->sincronizar($conexao);

        self::assertTrue($this->recarregar($conexao)->precisaReconectar());
        self::assertSame(EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, static::getContainer()->get(EstadoDoGoogleAgendaUseCase::class)->executar($tenant, $eu));
    }

    #[TestDox('Duas sincronias da mesma pessoa ao mesmo tempo: a segunda não roda (trava no banco)')]
    public function testTrava(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->compromisso($eu, $tenant, 'Audiência');
        $repo = static::getContainer()->get(\App\Agenda\Repository\ConexaoGoogleAgendaRepository::class);
        self::assertTrue($repo->ocupar((int) $conexao->getId(), new \DateTimeImmutable()));

        self::assertSame(['ocupada' => true], $this->sincronizar($conexao), 'trava ocupada: o handler tenta de novo em 30 s');
        self::assertSame([], $this->google->pedidos);
    }

    #[TestDox('A cadeia de 5 min: só uma mensagem reivindica a vez, pede uma sincronia por conexão e se reagenda')]
    public function testCadeia(): void
    {
        [, , $conexao] = $this->cenario();
        $this->em()->getConnection()->executeStatement('UPDATE agenda_google_varredura SET ultima_em = NULL WHERE id = 1');
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');
        $fila->reset();
        $handler = static::getContainer()->get(VarrerGoogleAgendaHandler::class);

        $handler(new VarrerGoogleAgenda());
        $handler(new VarrerGoogleAgenda());

        $proximas = array_values(array_filter($fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof VarrerGoogleAgenda));
        $sincronias = array_values(array_filter($fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof SincronizarGoogleDaPessoa && $e->getMessage()->conexaoId === $conexao->getId()));
        self::assertCount(1, $proximas);
        self::assertSame(300000, $proximas[0]->last(DelayStamp::class)?->getDelay());
        self::assertCount(1, $sincronias);
    }

    #[TestDox('Editar logo depois de enviar: o eco do envio que volta do Google não desfaz a edição, e ela vai ao Google')]
    public function testEcoNaoDesfazEdicao(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Audiência');
        $this->sincronizar($conexao);
        $evento = $this->recarregarEvento($evento);
        $evento->setTitulo('Audiência às 14h');
        $this->em()->flush();

        $this->sincronizar($conexao);

        self::assertSame('Audiência às 14h', $this->recarregarEvento($evento)->getTitulo());
        self::assertSame('Audiência às 14h', $this->google->eventos[(string) $this->recarregarEvento($evento)->getGoogleId()]['summary']);
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'e o eco da edição também não volta');
    }

    #[TestDox('Passar para "Só eu" logo depois de enviar: o eco não desfaz a troca, e o Google recebe como particular')]
    public function testEcoComTrocaParaSoEu(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Consulta médica');
        $this->sincronizar($conexao);
        $evento = $this->recarregarEvento($evento);
        $evento->setTitulo('Consulta')->setVisibilidade(Evento::VISIBILIDADE_SOMENTE_EU);
        $this->em()->flush();

        $this->sincronizar($conexao);

        $evento = $this->recarregarEvento($evento);
        self::assertSame('Consulta', $evento->getTitulo());
        self::assertSame('private', $this->google->eventos[(string) $evento->getGoogleId()]['visibility']);
    }

    #[TestDox('Recomeço do zero (410) depois de uma edição aqui: a lista completa não desfaz a edição')]
    public function testRecomecoNaoDesfazEdicao(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Reunião');
        $this->sincronizar($conexao);
        $this->sincronizar($conexao);
        $evento = $this->recarregarEvento($evento);
        $evento->setLocal('Sala 2');
        $this->em()->flush();
        $this->google->syncTokenVencido = true;

        $this->sincronizar($conexao);

        self::assertSame('Sala 2', $this->recarregarEvento($evento)->getLocal());
        self::assertSame('Sala 2', $this->google->eventos[(string) $this->recarregarEvento($evento)->getGoogleId()]['location']);
    }

    #[TestDox('Mudança de verdade no Google vale: vira edição do compromisso daqui')]
    public function testMudancaNoGoogleVale(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Reunião');
        $this->sincronizar($conexao);
        $id = (string) $this->recarregarEvento($evento)->getGoogleId();
        $this->google->paraTrazer = [['summary' => 'Reunião remarcada no celular'] + $this->google->eventos[$id]];

        $this->sincronizar($conexao);

        self::assertSame('Reunião remarcada no celular', $this->recarregarEvento($evento)->getTitulo());
    }

    #[TestDox('A mesma conta Google em dois escritórios: o compromisso de um escritório não entra no outro')]
    public function testDoisEscritorios(): void
    {
        [$eu, $tenantA, $conexaoA] = $this->cenario();
        $tenantB = (new Tenant())->setName('Tenant GOOGLE B ' . uniqid());
        $this->em()->persist($tenantB);
        $this->vincular($eu, $tenantB);
        $conexaoB = new ConexaoGoogleAgenda($tenantB, $eu, static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO'), 'pessoa@gmail.com', 'America/Sao_Paulo');
        $this->em()->persist($conexaoB);
        $this->em()->flush();
        $this->compromisso($eu, $tenantA, 'Audiência do escritório A');
        $this->sincronizar($conexaoA);

        $r = $this->sincronizar($conexaoB);

        self::assertSame(0, $r['trazidos']);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenantB->getId()]));
    }

    #[TestDox('Um evento que o Google recusa fica de fora até mudar; os outros vão e o syncToken avança')]
    public function testRecusaNaoTrava(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $recusado = $this->compromisso($eu, $tenant, 'Convite de outro');
        $this->compromisso($eu, $tenant, 'Audiência');
        $this->google->recusar = ['bjt' . $tenant->getId() . 'e' . $recusado->getId()];

        $r = $this->sincronizar($conexao);

        self::assertSame(1, $r['enviados']);
        self::assertSame('st-1', $this->recarregar($conexao)->getSyncToken());
        self::assertNull($this->recarregar($conexao)->getErro());
        $antes = count($this->google->pedidos);
        $this->sincronizar($conexao);
        self::assertNotContains('POST /calendar/v3/calendars/primary/events', array_slice($this->google->pedidos, $antes), 'não tenta de novo sem mudança');
    }

    #[TestDox('Quem perdeu o acesso à Agenda do escritório (saiu, mudou de papel) tem a conexão desfeita e revogada')]
    public function testSemAcessoDesconecta(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $id = (int) $conexao->getId();
        $this->em()->getConnection()->executeStatement('DELETE FROM user_tenant WHERE user_id = :u', ['u' => $eu->getId()]);

        $this->sincronizar($conexao);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE id = :id', ['id' => $id]));
        self::assertContains('POST /revoke', $this->google->pedidos);
    }

    #[TestDox('Aniversário de contato e local de trabalho não entram; convite de outro organizador entra mas não volta ao Google')]
    public function testTiposEConvites(): void
    {
        [, , $conexao] = $this->cenario();
        $quando = ['start' => ['dateTime' => '2030-03-10T10:00:00-03:00'], 'end' => ['dateTime' => '2030-03-10T11:00:00-03:00']];
        $this->google->paraTrazer = [
            ['id' => 'aniv1', 'eventType' => 'birthday', 'summary' => 'Aniversário'] + $quando,
            ['id' => 'loc1', 'eventType' => 'workingLocation', 'summary' => 'Escritório'] + $quando,
            ['id' => 'conv1', 'summary' => 'Reunião do cliente', 'organizer' => ['email' => 'cliente@x.com']] + $quando,
        ];

        self::assertSame(1, $this->sincronizar($conexao)['trazidos']);
        $convite = $this->em()->getRepository(Evento::class)->findOneBy(['googleId' => 'conv1']);
        self::assertSame(\App\Agenda\Service\CompromissoNoGoogle::SO_DO_GOOGLE, $convite?->getGoogleHash());
    }

    /** @return array{int, int, int} */
    private function sincronizar(ConexaoGoogleAgenda $conexao): array
    {
        $r = static::getContainer()->get(SincronizarGoogleAgendaUseCase::class)->executar((int) $conexao->getId(), new \DateTimeImmutable());
        $this->em()->clear();

        return $r ?? ['ocupada' => true];
    }

    /** @return array{User, Tenant, ConexaoGoogleAgenda} */
    private function cenario(): array
    {
        $tenant = (new Tenant())->setName('Tenant GOOGLE ' . uniqid());
        $this->em()->persist($tenant);
        [$eu] = $this->pessoa($tenant);
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO');
        $conexao = new ConexaoGoogleAgenda($tenant, $eu, $cifrado, 'pessoa@gmail.com', 'America/Sao_Paulo');
        $this->em()->persist($conexao);
        $this->em()->flush();

        return [$eu, $tenant, $conexao];
    }

    /** @return array{User} */
    private function pessoa(Tenant $tenant): array
    {
        $u = new User();
        $u->setEmail('google_' . uniqid() . '@test.com');
        $u->setFullName('Pessoa Google');
        $u->setRoles(['ROLE_USER']);
        $u->setIsActive(true);
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->vincular($u, $tenant);

        return [$u];
    }

    private function vincular(User $u, Tenant $tenant): void
    {
        $role = (new TenantRole())->setTenant($tenant)->setName('Gestor ' . uniqid())->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($u, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();
    }

    private function compromisso(User $criador, Tenant $tenant, string $titulo): Evento
    {
        $inicio = new \DateTimeImmutable('+2 days 10:00');
        $evento = (new Evento())->setTitulo($titulo)->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))
            ->setCriador($criador)->setTenant($tenant)->setTipo(TipoDeCompromisso::Audiencia);
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }

    private function recarregar(ConexaoGoogleAgenda $conexao): ConexaoGoogleAgenda
    {
        return $this->em()->find(ConexaoGoogleAgenda::class, $conexao->getId()) ?? throw new \LogicException('sumiu');
    }

    private function recarregarEvento(Evento $evento): Evento
    {
        return $this->em()->find(Evento::class, $evento->getId()) ?? throw new \LogicException('sumiu');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
