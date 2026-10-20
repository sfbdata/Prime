<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Entity\Tenant\Tenant;
use App\Pasta\Command\ResumoSemanalAoClienteCommand;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\EnviarResumoSemanalAoClienteUseCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;

/**
 * `app:push:resumo-semanal-cliente` (spec push-envio-automatico §3 e §4), com o relógio controlado
 * (quarta, 04/11/2026): `--dry-run` conta e não envia nem grava; a execução de verdade manda o resumo
 * da semana de 26/10 a 01/11 (a publicação de fora da semana não entra) e a segunda execução não repete.
 */
#[CoversClass(ResumoSemanalAoClienteCommand::class)]
#[CoversClass(EnviarResumoSemanalAoClienteUseCase::class)]
#[Group('pasta')]
final class ResumoSemanalAoClienteCommandTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const NUMERO = '07011345720258070007';

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-11-04 15:00:00'));
        self::bootKernel();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:push:resumo-semanal-cliente'));
    }

    /** @return array{0: Tenant, 1: Pasta} */
    private function cenario(): array
    {
        [$responsavel, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $pasta->setResponsavel($responsavel);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $cliente = $this->criarClientePF($tenant, $pasta, 'Maria da Silva');
        $cliente->setEmail('maria.resumo@exemplo.com.br');
        $this->criarPublicacao($tenant, $this->proximoDjenId(), self::NUMERO, '2026-10-28', $processo)->setTexto('DENTRO-DA-SEMANA');
        $this->criarPublicacao($tenant, $this->proximoDjenId(), self::NUMERO, '2026-10-21', $processo)->setTexto('FORA-DA-SEMANA');
        $linha = new EnvioAutomaticoDoCliente($tenant, $cliente, null, new \DateTimeImmutable());
        $linha->definir(ChaveDoEnvioAutomatico::ResumoSemanal, true, $responsavel, new \DateTimeImmutable());
        $this->em()->persist($linha);
        $this->em()->flush();

        return [$tenant, $pasta];
    }

    private function contarHistorico(int $pastaId): int
    {
        return \count($this->em()->getRepository(EnvioAoCliente::class)->findBy(['pasta' => $pastaId]));
    }

    #[TestDox('--dry-run --tenant: sucesso, mostra a semana 26/10 a 01/11, 1 pasta e 1 e-mail, e não envia nem grava')]
    public function testDryRun(): void
    {
        [$tenant, $pasta] = $this->cenario();
        $tester = $this->tester();

        $codigo = $tester->execute(['--dry-run' => true, '--tenant' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo, $tester->getDisplay());
        self::assertStringContainsString('--dry-run', $tester->getDisplay());
        self::assertMatchesRegularExpression('/26\/10 a 01\/11\s+1\s+1/', $tester->getDisplay());
        self::assertEmailCount(0);
        self::assertSame(0, $this->contarHistorico((int) $pasta->getId()));
    }

    #[TestDox('Execução de verdade: um e-mail com a movimentação da semana anterior (a da semana retrasada fica de fora) e uma linha no histórico; rodar de novo não repete')]
    public function testEnviaUmaVez(): void
    {
        [$tenant, $pasta] = $this->cenario();
        $pastaId = (int) $pasta->getId();

        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tenant' => (string) $tenant->getId()]));
        self::assertSame(Command::SUCCESS, $this->tester()->execute(['--tenant' => (string) $tenant->getId()]));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('maria.resumo@exemplo.com.br', $email->getTo()[0]->getAddress());
        self::assertSame('Resumo semanal do seu processo 0701134-57.2025.8.07.0007', $email->getSubject());
        self::assertEmailHtmlBodyContains($email, 'na semana de 26/10 a 01/11');
        self::assertEmailHtmlBodyContains($email, 'DENTRO-DA-SEMANA');
        self::assertEmailHtmlBodyNotContains($email, 'FORA-DA-SEMANA');
        $historico = $this->em()->getRepository(EnvioAoCliente::class)->findBy(['pasta' => $pastaId]);
        self::assertCount(1, $historico);
        self::assertSame('resumo semanal de 26/10 a 01/11', $historico[0]->getDescricao());
    }

    #[TestDox('--tenant inexistente: falha com a mensagem')]
    public function testTenantInexistente(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--tenant' => '999999999']));
        self::assertStringContainsString('não encontrado', $tester->getDisplay());
    }
}
