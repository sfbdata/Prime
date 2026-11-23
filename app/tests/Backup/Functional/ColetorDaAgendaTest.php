<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Agenda\Entity\Evento;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Backup\Coletor\ColetorDaAgenda;
use App\Backup\Coletor\ColetorDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPrazo;
use App\Pasta\Service\AcessoAPasta;
use App\Repository\EventoRepository;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Agenda no backup (spec backup, seções 2 e 4). Escritório 1: A (primário), C (vê prazos e pastas), D (só o módulo
 * Agenda: sem prazos, sem pastas). Escritório 2: B.
 */
#[CoversClass(ColetorDaAgenda::class)]
final class ColetorDaAgendaTest extends KernelTestCase
{
    use MontaBackupDeTesteTrait;

    private Tenant $t1;
    private Tenant $t2;
    private User $a;
    private User $b;
    private User $c;
    private User $d;

    protected function setUp(): void
    {
        $this->iniciarBackupDeTeste();
        [$this->a, $this->t1] = $this->criarEscritorio();
        [$this->b, $this->t2] = $this->criarEscritorio();
        $this->c = $this->criarColaborador($this->t1, 'Carlos Comum', ['modules.agenda.view', 'modules.pastas.view', 'resources.pasta.view', PermissoesDaAgenda::VER_PRAZOS]);
        $this->d = $this->criarColaborador($this->t1, 'Diana So Agenda', ['modules.agenda.view']);

        $this->semear();
    }

    /** @return list<ColetorDoBackup> */
    private function coletores(int $lote): array
    {
        $c         = static::getContainer();
        $permissao = $c->get(PermissionChecker::class);

        return [new ColetorDaAgenda($permissao, new PermissoesDaAgenda($permissao), $c->get(AcessoAPasta::class), $c->get(EventoRepository::class), $this->em())];
    }

    private function semear(): void
    {
        $p1 = $this->criarPasta($this->t1, 'P1 ABERTA');
        $p3 = $this->criarPasta($this->t1, 'P3 COM CADEADO');
        $p4 = $this->criarPasta($this->t1, 'P4 NA LIXEIRA');
        $pb = $this->criarPasta($this->t2, 'PB DO B');
        $p4->marcarExcluida($this->a, new \DateTimeImmutable());
        $this->trancar($p3, $this->a);

        $this->evento($this->t1, 'EVENTO ABERTO AO ESCRITORIO', $this->a, Evento::VISIBILIDADE_TODOS, [], $p1);
        $this->evento($this->t1, 'EVENTO PRIVADO DO PRIMARIO', $this->a, Evento::VISIBILIDADE_SOMENTE_EU);
        $this->evento($this->t1, 'EVENTO COM PARTICIPANTE CARLOS', $this->a, Evento::VISIBILIDADE_TODOS, [$this->c]);
        $this->evento($this->t1, 'EVENTO PRIVADO DO CARLOS', $this->c, Evento::VISIBILIDADE_SOMENTE_EU);
        $this->evento($this->t1, 'EVENTO NA PASTA DO CADEADO', $this->a, Evento::VISIBILIDADE_TODOS, [], $p3);
        $this->evento($this->t2, 'EVENTO DO ESCRITORIO B', $this->b, Evento::VISIBILIDADE_TODOS, [], $pb);

        $this->prazo($this->t1, $p1, 'PRAZO DA P1');
        $this->prazo($this->t1, $p3, 'PRAZO DA P3 CADEADO');
        $this->prazo($this->t1, $p4, 'PRAZO DA P4 LIXEIRA');
        $this->prazo($this->t2, $pb, 'PRAZO DO ESCRITORIO B');
        $this->em()->flush();
    }

    /** @param list<User> $participantes */
    private function evento(Tenant $tenant, string $titulo, User $criador, string $visibilidade, array $participantes = [], ?Pasta $pasta = null): void
    {
        $evento = (new Evento())->setTitulo($titulo)->setDescricao('DESCRICAO ' . $titulo)->setDataInicio(new \DateTimeImmutable('2026-12-01 10:00'))
            ->setDataFim(new \DateTimeImmutable('2026-12-01 11:00'))->setCriador($criador)->setTenant($tenant)->setVisibilidade($visibilidade)->setPasta($pasta);
        foreach ($participantes as $participante) {
            $evento->addParticipante($participante);
        }
        $evento->marcarImportadoDoGoogle();
        $this->em()->persist($evento);
    }

    private function prazo(Tenant $tenant, Pasta $pasta, string $titulo): void
    {
        $prazo = new PastaPrazo($tenant, $pasta, null);
        $prazo->definir(PastaPrazo::TIPO_PRAZO, $titulo, new \DateTimeImmutable('2026-12-05'), null, null);
        $this->em()->persist($prazo);
    }

    #[TestDox('Primário leva os compromissos que vê (os dele e os abertos), não o privado de outra pessoa nem nada do escritório 2')]
    public function testPrimarioLevaOQueVeENadaDoOutroEscritorio(): void
    {
        $zip  = $this->backupDe($this->a, $this->t1);
        $tudo = self::tudo($zip);

        foreach (['EVENTO ABERTO AO ESCRITORIO', 'EVENTO PRIVADO DO PRIMARIO', 'EVENTO COM PARTICIPANTE CARLOS', 'EVENTO NA PASTA DO CADEADO', 'PRAZO DA P1', 'PRAZO DA P3 CADEADO'] as $esperado) {
            self::assertStringContainsString($esperado, $tudo, $esperado);
        }
        foreach (['EVENTO PRIVADO DO CARLOS', 'ESCRITORIO B', 'PRAZO DA P4 LIXEIRA', 'P1 ABERTA'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
        self::assertStringContainsString('Compromissos da agenda: 4', $zip['LEIA-ME.txt']);
        self::assertStringContainsString('Prazos da agenda: 2', $zip['LEIA-ME.txt']);
    }

    #[TestDox('B (escritório 2) leva só o compromisso e o prazo do escritório 2')]
    public function testOutroEscritorioLevaSoOSeu(): void
    {
        $zip  = $this->backupDe($this->b, $this->t2);
        $json = self::json($zip, 'dados/agenda.json');

        self::assertSame(['EVENTO DO ESCRITORIO B'], array_column($json['compromissos'], 'titulo'));
        self::assertSame(['PRAZO DO ESCRITORIO B'], array_column($json['prazos'], 'titulo'));
        self::assertStringNotContainsString('EVENTO ABERTO', self::tudo($zip));
    }

    #[TestDox('C vê o aberto e o que participa, não o privado dos outros; prazo de pasta com cadeado e de pasta na lixeira ficam fora')]
    public function testVisibilidadeDoCompromissoECadeadoDoPrazo(): void
    {
        $zip  = $this->backupDe($this->c, $this->t1);
        $tudo = self::tudo($zip);
        $json = self::json($zip, 'dados/agenda.json');

        self::assertEqualsCanonicalizing(
            ['EVENTO ABERTO AO ESCRITORIO', 'EVENTO COM PARTICIPANTE CARLOS', 'EVENTO PRIVADO DO CARLOS', 'EVENTO NA PASTA DO CADEADO'],
            array_column($json['compromissos'], 'titulo'),
        );
        self::assertSame(['PRAZO DA P1'], array_column($json['prazos'], 'titulo'));
        foreach (['EVENTO PRIVADO DO PRIMARIO', 'PRAZO DA P3', 'PRAZO DA P4', 'ESCRITORIO B'] as $fora) {
            self::assertStringNotContainsString($fora, $tudo, $fora);
        }
        // O NUP da pasta com cadeado não vaza pelo compromisso.
        $doCadeado = array_values(array_filter($json['compromissos'], static fn (array $e): bool => $e['titulo'] === 'EVENTO NA PASTA DO CADEADO'));
        self::assertNull($doCadeado[0]['pasta']);
    }

    #[TestDox('D (sem "Ver prazos na agenda" e sem o módulo Pastas): compromissos sim, prazos não e sem o NUP da pasta')]
    public function testSemPermissaoDePrazosEPastas(): void
    {
        $json = self::json($this->backupDe($this->d, $this->t1), 'dados/agenda.json');

        self::assertContains('EVENTO ABERTO AO ESCRITORIO', array_column($json['compromissos'], 'titulo'));
        self::assertSame([], $json['prazos']);
        foreach ($json['compromissos'] as $evento) {
            self::assertNull($evento['pasta']);
        }
    }

    #[TestDox('Nada de Google: o JSON da agenda não leva token, conexão, id externo nem a marca de importação')]
    public function testNadaDoGoogleNoJson(): void
    {
        $tudo = self::json($this->backupDe($this->a, $this->t1), 'dados/agenda.json');
        $tudo = json_encode($tudo, JSON_THROW_ON_ERROR);

        foreach (['token', 'google', 'Google', 'sync', 'etag', 'refresh', 'secret'] as $proibido) {
            self::assertStringNotContainsString($proibido, $tudo, $proibido);
        }
    }

    #[TestDox('Sem o módulo agenda o coletor não roda: o ZIP traz só LEIA-ME e manifesto')]
    public function testSemModuloNaoRoda(): void
    {
        $semModulo = $this->criarColaborador($this->t1, 'Sem Modulo', [PermissoesDaAgenda::VER_PRAZOS]);

        self::assertSame(['LEIA-ME.txt', 'manifesto.json'], array_keys($this->backupDe($semModulo, $this->t1)));
    }

    #[TestDox('Lote de 1: o ZIP sai igual para A, C, D e B')]
    public function testLoteDeUmGeraOMesmoZip(): void
    {
        foreach ([[$this->a, $this->t1], [$this->c, $this->t1], [$this->d, $this->t1], [$this->b, $this->t2]] as [$usuario, $tenant]) {
            $this->assertLoteDeUmIgual($usuario, $tenant);
        }
    }
}
