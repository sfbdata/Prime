<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Command\CompararViradaDoBancoDeHorasCommand;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:ponto:banco:comparar-virada` (E17, P51 a): o banco de cada pessoa antes (só o ano corrente, como era) e depois
 * (lotes, atravessando o ano), por escritório, só com ids. Somente leitura. Os cenários de números usam só horas
 * pagas, para não dependerem do dia em que a suíte roda. Quem muda também pela jornada da folha (item 12) fica à parte.
 */
#[CoversClass(CompararViradaDoBancoDeHorasCommand::class)]
final class CompararViradaDoBancoDeHorasCommandTest extends KernelTestCase
{
    #[TestDox('Por escritório: antes (só o ano corrente), depois (lotes) e a diferença; só ids, sem nome nem e-mail')]
    public function testComparaAntesEDepoisPorEscritorio(): void
    {
        self::bootKernel();
        $ano = (int) (new \DateTimeImmutable())->format('Y');
        $escritorio = $this->escritorio();
        $outro = $this->escritorio();
        $alvo = $this->pessoa('Fulana Comparada');
        $colega = $this->pessoa('Colega Comparado');
        $this->horasPagas($escritorio, $alvo, $ano - 1, 12, 6000);  // +100h em dezembro do ano passado
        $this->horasPagas($escritorio, $alvo, $ano, 1, -600);       // -10h em janeiro: antes, só isto contava
        $this->horasPagas($escritorio, $colega, $ano, 1, 120);      // só o ano corrente: não muda
        $this->horasPagas($outro, $alvo, $ano - 1, 12, 999);        // outro escritório: linha própria, fora do filtro
        $ids = [(int) $escritorio->getId(), (int) $alvo->getId(), (int) $colega->getId()];
        $lancamentosAntes = $this->contarLancamentos();

        $tester = $this->rodar(['--escritorio' => (string) $ids[0]]);

        $tester->assertCommandIsSuccessful();
        $saida = $tester->getDisplay();
        self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+%d\s+-10h00m \(-600\)\s+\+90h00m \(5400\)\s+\+100h00m \(6000\)\s*$/m', $ids[0], $ids[1]), $saida);
        self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+%d\s+\+2h00m \(120\)\s+\+2h00m \(120\)\s+0h \(0\)\s*$/m', $ids[0], $ids[2]), $saida);
        self::assertStringContainsString('2 pessoas; 1 com saldo diferente.', $saida);
        self::assertStringNotContainsString('(999)', $saida, 'o filtro do escritório deixa o outro de fora');
        foreach (['Fulana', 'Colega', '@test.com'] as $pessoal) {
            self::assertStringNotContainsString($pessoal, $saida, 'só ids');
        }
        self::assertSame($lancamentosAntes, $this->contarLancamentos(), 'somente leitura');
    }

    #[TestDox('--so-diferentes lista só quem muda de saldo')]
    public function testSoDiferentes(): void
    {
        self::bootKernel();
        $ano = (int) (new \DateTimeImmutable())->format('Y');
        $escritorio = $this->escritorio();
        $muda = $this->pessoa('Muda');
        $igual = $this->pessoa('Igual');
        $this->horasPagas($escritorio, $muda, $ano - 1, 11, 60);
        $this->horasPagas($escritorio, $igual, $ano, 2, 60);
        $escritorioId = (int) $escritorio->getId();
        $mudaId = (int) $muda->getId();
        $igualId = (int) $igual->getId();

        $saida = $this->rodar(['--escritorio' => (string) $escritorioId, '--so-diferentes' => true])->getDisplay();

        self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+%d\s+0h \(0\)\s+\+1h00m \(60\)\s+\+1h00m \(60\)\s*$/m', $escritorioId, $mudaId), $saida);
        self::assertDoesNotMatchRegularExpression(sprintf('/^\s*%d\s+%d\s/m', $escritorioId, $igualId), $saida);
        self::assertStringContainsString('2 pessoas; 1 com saldo diferente.', $saida);
    }

    #[TestDox('À parte, com a contagem: quem não tem jornada pessoal e quem tem dias antes da primeira versão')]
    public function testListaAParteQuemMudaPelaJornadaDaFolha(): void
    {
        self::bootKernel();
        $escritorio = $this->escritorio();
        $semJornada = $this->pessoa('Sem Jornada');
        $versaoNoMeio = $this->pessoa('Versão no Meio');
        $comJornada = $this->pessoa('Com Jornada');
        $semBatida = $this->pessoa('Sem Batida');
        $primeiroDia = new \DateTimeImmutable('first day of last month');
        foreach ([$semJornada, $versaoNoMeio, $comJornada] as $pessoa) {
            $this->diaTrabalhado($escritorio, $pessoa, $primeiroDia);
        }
        $this->jornada($escritorio, $versaoNoMeio, $primeiroDia->modify('+10 days')); // o dia trabalhado fica antes dela
        $this->jornada($escritorio, $comJornada, $primeiroDia->modify('-1 month'));   // cobre a contagem inteira
        $this->horasPagas($escritorio, $semBatida, (int) $primeiroDia->format('Y'), (int) $primeiroDia->format('n'), 60); // sem início da contagem
        $id = static fn (object $entidade): int => (int) $entidade->getId();

        $saida = $this->rodar(['--escritorio' => (string) $id($escritorio)])->getDisplay();

        [$lista, $aParte] = explode('À parte: mudam também pela jornada da folha', $saida, 2);
        foreach ([$comJornada, $semBatida] as $pessoa) {
            self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+%d\s+\S/m', $id($escritorio), $id($pessoa)), $lista);
        }
        self::assertMatchesRegularExpression('/^\s*2 pessoas; [01] com saldo diferente\.$/m', $lista);
        foreach ([[$semJornada, 'sem jornada pessoal'], [$versaoNoMeio, 'dias antes da primeira versão']] as [$pessoa, $motivo]) {
            self::assertDoesNotMatchRegularExpression(sprintf('/^\s*%d\s+%d\s/m', $id($escritorio), $id($pessoa)), $lista);
            self::assertMatchesRegularExpression(sprintf('/^\s*%d\s+%d\s+%s\s+\S/m', $id($escritorio), $id($pessoa), $motivo), $aParte);
        }
        self::assertMatchesRegularExpression('/^\s*2 pessoas à parte; [012] com saldo diferente\.$/m', $aParte);
    }

    /** @param array<string, string|bool> $opcoes */
    private function rodar(array $opcoes): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:ponto:banco:comparar-virada'));
        self::assertSame(Command::SUCCESS, $tester->execute($opcoes));

        return $tester;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function escritorio(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório Virada ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function pessoa(string $nome): User
    {
        $user = new User();
        $user->setEmail('virada_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function horasPagas(Tenant $tenant, User $user, int $ano, int $mes, int $minutos): void
    {
        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($user);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de teste');
        $lancamento->setCriadoPor($user);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $this->em()->persist($lancamento);
        $this->em()->flush();
    }

    private function diaTrabalhado(Tenant $tenant, User $user, \DateTimeImmutable $dia): void
    {
        foreach ([RegistroPonto::TIPO_ENTRADA => '08:00', RegistroPonto::TIPO_SAIDA => '17:00'] as $tipo => $hora) {
            $registro = new RegistroPonto();
            $registro->setUser($user);
            $registro->setTenant($tenant);
            $registro->setTipo($tipo);
            $registro->setDataHora(new \DateTime($dia->format('Y-m-d ') . $hora));
            $registro->setSedeNomeSnapshot('Teste');
            $this->em()->persist($registro);
        }
        $this->em()->flush();
    }

    private function jornada(Tenant $tenant, User $user, \DateTimeImmutable $valeAPartirDe): void
    {
        $jornada = (new JornadaColaborador())->setUser($user)->setTenant($tenant)->setCargaHorariaSemanal(2400);
        $jornada->setValeAPartirDe($valeAPartirDe);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('17:00')->setMinutosBloco(480));
        $this->em()->persist($jornada);
        $this->em()->flush();
    }

    private function contarLancamentos(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ponto_lancamento_horas_pagas');
    }
}
