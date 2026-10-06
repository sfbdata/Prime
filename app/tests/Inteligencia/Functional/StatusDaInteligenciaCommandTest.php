<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Command\StatusDaInteligenciaCommand;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\ProvedorNaoConfigurado;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use App\Tests\Inteligencia\Support\ProvedorFalso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(StatusDaInteligenciaCommand::class)]
final class StatusDaInteligenciaCommandTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    /** O comando como em dev/prod: provedor dormente e flag desligada. */
    private function testerSemProvedor(): CommandTester
    {
        $c = static::getContainer();

        return new CommandTester(new StatusDaInteligenciaCommand(
            new ProvedorNaoConfigurado(),
            $c->get(AnaliseDeInteligenciaRepository::class),
            $c->get(ConfiguracaoDeInteligenciaRepository::class),
            false,
        ));
    }

    #[TestDox('imprime nao_configurado, flag desligada e "nenhuma análise" quando não há nada')]
    public function testSemProvedorESemAnalises(): void
    {
        self::bootKernel();

        $tester = $this->testerSemProvedor();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $saida = $tester->getDisplay();
        self::assertStringContainsString('nao_configurado', $saida);
        self::assertStringContainsString('não configurado', $saida);
        self::assertStringContainsString('desligada', $saida);
        self::assertStringContainsString('Nenhuma análise registrada', $saida);
    }

    #[TestDox('conta as análises por escritório e status e os escritórios com a IA ligada')]
    public function testContagens(): void
    {
        self::bootKernel();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $this->ligarIaNoTenant($tenant, $user);
        $this->criarAnalisePendente($tenant, $user, $pasta);
        $concluida = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($concluida);
        $falha = $this->criarAnalisePendente($tenant, $user, $pasta);
        $falha->falhar('x');
        $this->em()->flush();

        $tester = $this->testerSemProvedor();
        $tester->execute([]);

        $saida = $tester->getDisplay();
        self::assertStringContainsString((string) $tenant->getName(), $saida);
        self::assertMatchesRegularExpression('/Escritórios com a IA ligada\s+1/u', $saida);

        // A linha do tenant na tabela (estilo do SymfonyStyle, sem bordas verticais):
        // id   nome   1 pendente   0 processando   1 concluída   1 falha   0 indisponível
        self::assertMatchesRegularExpression(
            sprintf('/^\s*%d\s+%s\s+1\s+0\s+1\s+1\s+0\s*$/mu', (int) $tenant->getId(), preg_quote((string) $tenant->getName(), '/')),
            $saida,
            'pendentes, processando, concluídas, falhas, indisponíveis',
        );
    }

    #[TestDox('o comando registrado no container (app:inteligencia:status) roda com o provedor do ambiente')]
    public function testComandoRegistrado(): void
    {
        self::bootKernel();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:inteligencia:status'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString(ProvedorFalso::NOME, $tester->getDisplay(), 'em teste o alias aponta para o ProvedorFalso');
        self::assertStringContainsString('ligada', $tester->getDisplay(), 'em teste a flag da plataforma está ligada');
    }
}
