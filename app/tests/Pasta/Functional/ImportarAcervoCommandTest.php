<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Command\ImportarAcervoCommand;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * O comando não tinha teste nenhum (`grep ImportarAcervo app/tests` era vazio) e a numeração
 * automática (R1) o regrediu em silêncio: com `CriarPastaDTO.nup` virando `?string` e o UseCase
 * GERANDO quando recebe vazio, uma linha de CSV sem número deixou de ser pulada e passou a criar
 * pasta com número inventado. A importação é HISTÓRICA — o número tem de vir da origem.
 *
 * Estes testes travam a correção. Sem eles, a próxima mudança no UseCase reabre o buraco.
 */
#[CoversClass(ImportarAcervoCommand::class)]
final class ImportarAcervoCommandTest extends KernelTestCase
{
    use Factories;

    private string $csv = '';

    protected function tearDown(): void
    {
        if ($this->csv !== '' && file_exists($this->csv)) {
            @unlink($this->csv);
        }

        parent::tearDown();
    }

    /** O comando lê com `fgetcsv($h, 0, ';', '"')` — o separador é PONTO E VÍRGULA. */
    private function csvCom(string $conteudo): string
    {
        $this->csv = (string) tempnam(sys_get_temp_dir(), 'acervo_') . '.csv';
        file_put_contents($this->csv, $conteudo);

        return $this->csv;
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:acervo:importar'));
    }

    #[TestDox('R1/regressão: linha SEM número é PULADA — a importação não inventa número')]
    public function testLinhaSemNumeroEhPulada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $csv = $this->csvCom("nup;cliente;acao\n;CLIENTE SEM NUMERO;EXECUCAO\n777;CLIENTE OK;COBRANCA\n");

        $tester = $this->tester();
        $tester->execute([
            '--csv'        => $csv,
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
        ]);
        $tester->assertCommandIsSuccessful();

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pastas = $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant->_real()]);

        self::assertCount(1, $pastas, 'a linha sem número virou pasta — número inventado pela importação');
        self::assertSame('777', $pastas[0]->getNup());
        self::assertStringContainsString('[pulada]', $tester->getDisplay());
    }

    #[TestDox('a linha sem número entra no contador de PULADAS, não no de importadas')]
    public function testLinhaSemNumeroContaComoPulada(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        $csv = $this->csvCom("nup;cliente;acao\n;SEM UM;X\n   ;SEM DOIS;Y\n10;COM NUMERO;Z\n");

        $tester = $this->tester();
        $tester->execute([
            '--csv'        => $csv,
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
        ]);
        $tester->assertCommandIsSuccessful();

        $saida = $tester->getDisplay();
        self::assertSame(2, substr_count($saida, '[pulada]'), 'as duas linhas sem número deviam ser puladas');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertCount(1, $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant->_real()]));
    }

    #[TestDox('o número do CSV é preservado como veio — nunca substituído pelo gerador')]
    public function testNumeroDoCsvEhPreservado(): void
    {
        self::bootKernel();
        $tenant = TenantFactory::createOne();
        $user   = UserFactory::createOne();

        // Números fora de sequência e com sufixo de letra: é o que o acervo real tem.
        $csv = $this->csvCom("nup;cliente;acao\n900;A;X\n10A;B;Y\n10B;C;Z\n");

        $tester = $this->tester();
        $tester->execute([
            '--csv'        => $csv,
            '--tenant-id'  => (string) $tenant->getId(),
            '--usuario-id' => (string) $user->getId(),
        ]);
        $tester->assertCommandIsSuccessful();

        $em    = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $nups  = array_map(
            static fn (Pasta $p): ?string => $p->getNup(),
            $em->getRepository(Pasta::class)->findBy(['tenant' => $tenant->_real()], ['id' => 'ASC']),
        );

        self::assertSame(['900', '10A', '10B'], $nups, 'o gerador atropelou o número que veio da planilha');
    }
}
