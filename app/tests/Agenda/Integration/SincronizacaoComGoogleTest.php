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
use App\Agenda\Service\CompromissoNoGoogle;
use App\Agenda\Service\LevarAoGoogle;
use App\Agenda\UseCase\EstadoDoGoogleAgendaUseCase;
use App\Agenda\UseCase\SincronizarGoogleAgendaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Sync\Service\CifradorDeSegredo;
use App\Tests\Agenda\GoogleFalso;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Sincronia do Google Agenda de ponta a ponta contra um Google falso (MockHttpClient) e o banco de teste, no
 * modelo do dono de 20/10/2026 (agenda do escritório na conta de cada pessoa). Spec: docs/specs/agenda-google.md.
 */
#[CoversClass(SincronizarGoogleAgendaUseCase::class)]
#[CoversClass(LevarAoGoogle::class)]
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

    #[TestDox('A primeira sincronia cria na conta a agenda com o nome do escritório e trabalha só nela (nunca na principal)')]
    public function testAgendaDoEscritorio(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->compromisso($eu, $tenant, 'Audiência');

        $this->sincronizar($conexao);

        $agenda = $this->agendaDe($conexao);
        self::assertNotNull($agenda);
        self::assertSame($tenant->getName(), $this->google->agendas[$agenda]);
        self::assertContains('POST /calendar/v3/calendars', $this->google->pedidos);
        self::assertSame([], array_filter($this->google->pedidos, static fn (string $p): bool => str_contains($p, '/primary')), 'a agenda principal não é tocada');
        self::assertCount(1, $this->google->eventos[$agenda]);
    }

    #[TestDox('Criado no Google, na agenda do escritório: entra visível à equipe, marcado como importado; não volta ao Google')]
    public function testTrazDoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $this->google->paraTrazer[$this->agendaDe($conexao)] = [['id' => 'abc123', 'summary' => 'Dentista', 'start' => ['dateTime' => '2030-03-10T15:00:00-03:00'], 'end' => ['dateTime' => '2030-03-10T16:00:00-03:00']]];

        $r = $this->sincronizar($conexao);
        $evento = $this->eventoPeloGoogle($conexao, 'abc123');

        self::assertSame(1, $r['trazidos']);
        self::assertSame('Dentista', $evento->getTitulo());
        self::assertSame(Evento::VISIBILIDADE_TODOS, $evento->getVisibilidade(), 'decisão a do dono: visível à equipe');
        self::assertTrue($evento->isImportadoDoGoogle());
        self::assertSame(TipoDeCompromisso::Meus, $evento->getTipo());
        self::assertSame($eu->getId(), $evento->getCriador()?->getId());
        self::assertSame(0, $r['enviados'], 'o que veio do Google não volta para lá');
        self::assertSame(0, $this->sincronizar($conexao)['enviados']);
    }

    #[TestDox('Convidados do evento do Google que são da equipe (e-mail do cadastro ou da conta Google ligada) viram participantes; o de fora é ignorado')]
    public function testConvidadosDaEquipe(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        [$porCadastro] = $this->pessoa($tenant);
        [$porConta] = $this->pessoa($tenant);
        $this->conectar($porConta, $tenant, 'colega.pessoal@gmail.com');
        $this->sincronizar($conexao);
        $convidados = [['email' => strtoupper((string) $porCadastro->getEmail())], ['email' => 'colega.pessoal@gmail.com'], ['email' => 'cliente@fora.com.br'], ['email' => 'pessoa@gmail.com', 'self' => true]];
        $this->google->paraTrazer[$this->agendaDe($conexao)] = [['id' => 'reu1', 'summary' => 'Reunião', 'attendees' => $convidados, 'start' => ['date' => '2030-04-01'], 'end' => ['date' => '2030-04-02']]];

        $this->sincronizar($conexao);
        $evento = $this->eventoPeloGoogle($conexao, 'reu1');
        $ids = array_map(static fn (User $u): ?int => $u->getId(), $evento->getParticipantes()->toArray());
        sort($ids);
        $esperados = [$porCadastro->getId(), $porConta->getId()];
        sort($esperados);

        self::assertSame($esperados, $ids);
        self::assertStringNotContainsString('fora.com.br', (string) json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM evento WHERE id = :id', ['id' => $evento->getId()])));
    }

    #[TestDox('Compromisso criado aqui vai ao Google com id nosso; sem mudança não vai de novo; mudou, vai como atualização')]
    public function testLevaAoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Audiência');

        self::assertSame(1, $this->sincronizar($conexao)['enviados']);
        $agenda = (string) $this->agendaDe($conexao);
        $id = 'bjt' . $tenant->getId() . 'e' . $evento->getId();
        self::assertSame($id, $this->ligacao($conexao, $evento)['google_id']);
        self::assertSame(0, $this->sincronizar($conexao)['enviados'], 'sem mudança, nada vai');

        $evento = $this->recarregarEvento($evento);
        $evento->setTitulo('Audiência remarcada');
        $this->em()->flush();
        self::assertSame(1, $this->sincronizar($conexao)['enviados']);
        self::assertSame('Audiência remarcada', $this->google->eventos[$agenda][$id]['summary']);
        self::assertContains('PUT /calendar/v3/calendars/' . rawurlencode($agenda) . '/events/' . $id, $this->google->pedidos);
    }

    #[TestDox('Enviar duas vezes (ligação perdida) não duplica no Google: o 409 vira atualização')]
    public function testInserirDuasVezesNaoDuplica(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Reunião');
        $this->sincronizar($conexao);
        $this->em()->getConnection()->executeStatement('DELETE FROM agenda_google_ligacao WHERE evento_id = :id', ['id' => $evento->getId()]);

        $this->google->paraTrazer[(string) $this->agendaDe($conexao)] = [$this->google->eventos[(string) $this->agendaDe($conexao)]['bjt' . $tenant->getId() . 'e' . $evento->getId()]];

        $this->sincronizar($conexao);

        self::assertCount(1, $this->google->eventos[(string) $this->agendaDe($conexao)]);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenant->getId()]), 'o id nosso que volta sem ligação religa, não importa uma cópia');
    }

    #[TestDox('O participante conectado recebe a cópia na agenda dele, com o aviso de só leitura; "Só eu" não vai a participante')]
    public function testCopiaDoParticipante(): void
    {
        [$eu, $tenant] = $this->cenario();
        [$colega] = $this->pessoa($tenant);
        $daColega = $this->conectar($colega, $tenant, 'colega@gmail.com');
        $reuniao = $this->compromisso($eu, $tenant, 'Reunião de pauta', [$colega]);
        $particular = $this->compromisso($eu, $tenant, 'Consulta', [$colega], Evento::VISIBILIDADE_SOMENTE_EU);

        $this->sincronizar($daColega);

        $agenda = (string) $this->agendaDe($daColega);
        $id = 'bjt' . $tenant->getId() . 'e' . $reuniao->getId();
        self::assertSame(['bjt' . $tenant->getId() . 'e' . $reuniao->getId()], array_keys($this->google->eventos[$agenda]));
        self::assertStringStartsWith('Compromisso de Pessoa Google no BlueJus.', (string) $this->google->eventos[$agenda][$id]['description']);
        self::assertNull($this->ligacao($daColega, $particular));
    }

    #[TestDox('A cópia é só leitura: mexida no Google volta como aqui; apagada lá fica apagada até mudar aqui; quem sai da lista perde a cópia')]
    public function testCopiaSoLeitura(): void
    {
        [$eu, $tenant] = $this->cenario();
        [$colega] = $this->pessoa($tenant);
        $daColega = $this->conectar($colega, $tenant, 'colega@gmail.com');
        $reuniao = $this->compromisso($eu, $tenant, 'Reunião de pauta', [$colega]);
        $this->sincronizar($daColega);
        $agenda = (string) $this->agendaDe($daColega);
        $id = 'bjt' . $tenant->getId() . 'e' . $reuniao->getId();

        $this->google->paraTrazer[$agenda] = [['summary' => 'Mexi aqui'] + $this->google->eventos[$agenda][$id]];
        $this->sincronizar($daColega);
        self::assertSame('Reunião de pauta', $this->recarregarEvento($reuniao)->getTitulo(), 'a mudança na cópia não vale aqui');
        $this->sincronizar($daColega);
        self::assertSame('Reunião de pauta', $this->google->eventos[$agenda][$id]['summary'], 'e volta como aqui no Google');

        $this->google->paraTrazer[$agenda] = [['id' => $id, 'status' => 'cancelled']];
        unset($this->google->eventos[$agenda][$id]);
        $this->sincronizar($daColega);
        self::assertSame(0, $this->sincronizar($daColega)['enviados'], 'apagada lá, não volta sem mudança');

        $reuniao = $this->recarregarEvento($reuniao);
        $reuniao->removeParticipante($this->em()->find(User::class, $colega->getId()));
        $this->em()->flush();
        $r = $this->sincronizar($daColega);
        self::assertSame(1, $r['apagados'], 'saiu da lista: a cópia sai da agenda dele');
        self::assertNull($this->ligacao($daColega, $reuniao));
    }

    #[TestDox('Cancelado no Google não apaga aqui: o compromisso fica, na situação "cancelado"')]
    public function testCanceladoNoGoogleFica(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Audiência');
        $this->sincronizar($conexao);
        $this->google->paraTrazer[(string) $this->agendaDe($conexao)] = [['id' => $this->ligacao($conexao, $evento)['google_id'], 'status' => 'cancelled']];

        $this->sincronizar($conexao);

        $evento = $this->recarregarEvento($evento);
        self::assertSame(Evento::STATUS_CANCELADO, $evento->getStatus());
        self::assertSame('Audiência', $evento->getTitulo());
    }

    #[TestDox('Excluído aqui (de qualquer tela) é apagado na agenda do criador e na do participante, na sincronia seguinte de cada um')]
    public function testExcluidoAquiApagaNoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        [$colega] = $this->pessoa($tenant);
        $daColega = $this->conectar($colega, $tenant, 'colega@gmail.com');
        $evento = $this->compromisso($eu, $tenant, 'Prazo pessoal', [$colega]);
        $this->sincronizar($conexao);
        $this->sincronizar($daColega);
        /** @var InMemoryTransport $fila */
        $fila = static::getContainer()->get('messenger.transport.async');
        $fila->reset();

        $this->em()->remove($this->recarregarEvento($evento));
        $this->em()->flush();
        $pedidas = array_filter($fila->getSent(), static fn ($e): bool => $e->getMessage() instanceof SincronizarGoogleDaPessoa);
        $r1 = $this->sincronizar($conexao);
        $r2 = $this->sincronizar($daColega);

        self::assertCount(2, $pedidas, 'excluir pede a sincronia do criador e do participante');
        self::assertSame([1, 1], [$r1['apagados'], $r2['apagados']]);
        self::assertSame([], $this->google->eventos[(string) $this->agendaDe($conexao)]);
        self::assertSame([], $this->google->eventos[(string) $this->agendaDe($daColega)]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_exclusao'));
    }

    #[TestDox('A pessoa apagou a agenda do escritório no Google: a sincronia seguinte cria outra e manda tudo de novo')]
    public function testAgendaApagadaNoGoogle(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->compromisso($eu, $tenant, 'Audiência');
        $this->sincronizar($conexao);
        $antiga = (string) $this->agendaDe($conexao);
        $this->google->apagarAgendaNoGoogle($antiga);

        $this->sincronizar($conexao);
        $this->sincronizar($conexao);

        $nova = (string) $this->agendaDe($conexao);
        self::assertNotSame($antiga, $nova);
        self::assertCount(1, $this->google->eventos[$nova]);
        self::assertNull($this->recarregar($conexao)->getErro());
    }

    #[TestDox('syncToken vencido (410) recomeça do zero; autorização revogada na conta Google volta o painel a "Não conectado"')]
    public function testRecomecoEAutorizacaoRevogada(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $this->google->syncTokenVencido = true;
        $this->google->paraTrazer[(string) $this->agendaDe($conexao)] = [['id' => 'zz1', 'summary' => 'Depois do 410', 'start' => ['date' => '2030-05-01'], 'end' => ['date' => '2030-05-02']]];
        self::assertSame(1, $this->sincronizar($conexao)['trazidos']);

        $this->google->recusarAutorizacao = true;
        $this->sincronizar($conexao);

        self::assertTrue($this->recarregar($conexao)->precisaReconectar());
        self::assertSame(EstadoDoGoogleAgendaUseCase::NAO_CONECTADO, static::getContainer()->get(EstadoDoGoogleAgendaUseCase::class)->executar($tenant, $eu));
    }

    #[TestDox('Duas sincronias da mesma conexão ao mesmo tempo: a segunda não roda (trava no banco)')]
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
        self::assertSame('Audiência às 14h', $this->noGoogle($conexao, $evento)['summary']);
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

        self::assertSame('Consulta', $this->recarregarEvento($evento)->getTitulo());
        self::assertSame('private', $this->noGoogle($conexao, $evento)['visibility']);
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
        self::assertSame('Sala 2', $this->noGoogle($conexao, $evento)['location']);
    }

    #[TestDox('Mudança de verdade no Google, no compromisso de quem é dono da conta, vale: vira edição daqui')]
    public function testMudancaNoGoogleVale(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $evento = $this->compromisso($eu, $tenant, 'Reunião');
        $this->sincronizar($conexao);
        $this->google->paraTrazer[(string) $this->agendaDe($conexao)] = [['summary' => 'Reunião remarcada no celular'] + $this->noGoogle($conexao, $evento)];

        $this->sincronizar($conexao);

        self::assertSame('Reunião remarcada no celular', $this->recarregarEvento($evento)->getTitulo());
    }

    #[TestDox('A mesma conta Google em dois escritórios: cada um tem a sua agenda nela, e um não mistura com o outro')]
    public function testDoisEscritorios(): void
    {
        [$eu, $tenantA, $conexaoA] = $this->cenario();
        $tenantB = (new Tenant())->setName('Tenant GOOGLE B ' . uniqid());
        $this->em()->persist($tenantB);
        $this->vincular($eu, $tenantB);
        $conexaoB = $this->conectar($eu, $tenantB, 'pessoa@gmail.com');
        $this->compromisso($eu, $tenantA, 'Audiência do escritório A');
        $this->sincronizar($conexaoA);

        $r = $this->sincronizar($conexaoB);

        self::assertNotSame($this->agendaDe($conexaoA), $this->agendaDe($conexaoB));
        self::assertSame(0, $r['trazidos']);
        self::assertSame([], $this->google->eventos[(string) $this->agendaDe($conexaoB)]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM evento WHERE tenant_id = :t', ['t' => $tenantB->getId()]));
    }

    #[TestDox('Um evento que o Google recusa fica de fora até mudar; os outros vão e o syncToken avança')]
    public function testRecusaNaoTrava(): void
    {
        [$eu, $tenant, $conexao] = $this->cenario();
        $recusado = $this->compromisso($eu, $tenant, 'Recusado');
        $this->compromisso($eu, $tenant, 'Audiência');
        $this->google->recusar = ['bjt' . $tenant->getId() . 'e' . $recusado->getId()];

        $r = $this->sincronizar($conexao);

        self::assertSame(1, $r['enviados']);
        self::assertNotNull($this->recarregar($conexao)->getSyncToken());
        self::assertNull($this->recarregar($conexao)->getErro());
        $antes = count($this->google->pedidos);
        $this->sincronizar($conexao);
        self::assertSame([], array_filter(array_slice($this->google->pedidos, $antes), static fn (string $p): bool => str_starts_with($p, 'POST ') && str_ends_with($p, '/events')), 'não tenta de novo sem mudança');
    }

    #[TestDox('Quem perdeu o acesso à Agenda do escritório (saiu, mudou de papel) tem a conexão desfeita e revogada')]
    public function testSemAcessoDesconecta(): void
    {
        [$eu, , $conexao] = $this->cenario();
        $id = (int) $conexao->getId();
        $this->em()->getConnection()->executeStatement('DELETE FROM user_tenant WHERE user_id = :u', ['u' => $eu->getId()]);

        $this->sincronizar($conexao);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM agenda_google_conexao WHERE id = :id', ['id' => $id]));
        self::assertContains('POST /revoke', $this->google->pedidos);
    }

    #[TestDox('Aniversário e local de trabalho não entram; evento de outro organizador entra mas não volta ao Google')]
    public function testTiposEConvites(): void
    {
        [, , $conexao] = $this->cenario();
        $this->sincronizar($conexao);
        $quando = ['start' => ['dateTime' => '2030-03-10T10:00:00-03:00'], 'end' => ['dateTime' => '2030-03-10T11:00:00-03:00']];
        $this->google->paraTrazer[(string) $this->agendaDe($conexao)] = [
            ['id' => 'aniv1', 'eventType' => 'birthday', 'summary' => 'Aniversário'] + $quando,
            ['id' => 'loc1', 'eventType' => 'workingLocation', 'summary' => 'Escritório'] + $quando,
            ['id' => 'conv1', 'summary' => 'Reunião do cliente', 'organizer' => ['email' => 'cliente@x.com']] + $quando,
        ];

        self::assertSame(1, $this->sincronizar($conexao)['trazidos']);
        self::assertSame(CompromissoNoGoogle::SO_DO_GOOGLE, $this->em()->getConnection()->fetchOne("SELECT hash FROM agenda_google_ligacao WHERE google_id = 'conv1'"));
    }

    /** @return array<string, int> */
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

        return [$eu, $tenant, $this->conectar($eu, $tenant, 'pessoa@gmail.com')];
    }

    private function conectar(User $pessoa, Tenant $tenant, string $conta): ConexaoGoogleAgenda
    {
        $cifrado = static::getContainer()->get(CifradorDeSegredo::class)->cifrar('REFRESH-SECRETO');
        $conexao = new ConexaoGoogleAgenda($tenant, $pessoa, $cifrado, $conta, 'America/Sao_Paulo');
        $this->em()->persist($conexao);
        $this->em()->flush();

        return $conexao;
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

    /** @param list<User> $participantes */
    private function compromisso(User $criador, Tenant $tenant, string $titulo, array $participantes = [], string $visibilidade = Evento::VISIBILIDADE_TODOS): Evento
    {
        $inicio = new \DateTimeImmutable('+2 days 10:00');
        $evento = (new Evento())->setTitulo($titulo)->setDataInicio($inicio)->setDataFim($inicio->modify('+1 hour'))
            ->setCriador($this->em()->find(User::class, $criador->getId()))->setTenant($this->em()->find(Tenant::class, $tenant->getId()))
            ->setTipo(TipoDeCompromisso::Audiencia)->setVisibilidade($visibilidade);
        foreach ($participantes as $p) {
            $evento->addParticipante($this->em()->find(User::class, $p->getId()));
        }
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }

    private function agendaDe(ConexaoGoogleAgenda $conexao): ?string
    {
        return $this->recarregar($conexao)->getCalendarioId();
    }

    /** @return array{google_id: string, hash: ?string}|null */
    private function ligacao(ConexaoGoogleAgenda $conexao, Evento $evento): ?array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT google_id, hash FROM agenda_google_ligacao WHERE conexao_id = :c AND evento_id = :e', ['c' => $conexao->getId(), 'e' => $evento->getId()]);

        return false === $linha ? null : $linha;
    }

    /** @return array<string, mixed> o evento como está na agenda do escritório da conexão */
    private function noGoogle(ConexaoGoogleAgenda $conexao, Evento $evento): array
    {
        return $this->google->eventos[(string) $this->agendaDe($conexao)][(string) $this->ligacao($conexao, $evento)['google_id']];
    }

    private function eventoPeloGoogle(ConexaoGoogleAgenda $conexao, string $googleId): Evento
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT evento_id FROM agenda_google_ligacao WHERE conexao_id = :c AND google_id = :g', ['c' => $conexao->getId(), 'g' => $googleId]);

        return $this->em()->find(Evento::class, (int) $id) ?? throw new \LogicException('não veio');
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
