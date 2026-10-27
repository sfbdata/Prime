<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Functional;

use App\Indicacao\Repository\IndicacaoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A garantia de que dois pedidos simultâneos sobre o mesmo indicador passam um de cada vez (baixa, crédito, estorno,
 * bloquear, aprovar, apagar), provada no banco com DUAS conexões: enquanto a primeira está dentro de
 * `emTransacaoTravada`, a segunda não consegue a mesma trava. O DAMA envolve a conexão do container numa transação, que
 * segura a trava até o fim do teste; a segunda conexão é independente e só tenta a trava, sem gravar nada.
 */
#[CoversClass(IndicacaoRepository::class)]
final class TravaDoIndicadorNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IndicacaoRepository $indicacoes;
    private Connection $outra;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->indicacoes = static::getContainer()->get(IndicacaoRepository::class);
        // Conexão própria: sem o DAMA, enxerga o banco como outro processo enxergaria.
        $this->outra = DriverManager::getConnection($this->em->getConnection()->getParams());
    }

    protected function tearDown(): void
    {
        $this->outra->close();
        parent::tearDown();
    }

    private function conseguiuATrava(int $userId): bool
    {
        return (bool) $this->outra->fetchOne('SELECT pg_try_advisory_xact_lock(?, ?)', [IndicacaoRepository::CLASSE_TRAVA, $userId]);
    }

    #[TestDox('com a trava do indicador em mãos, outra conexão não a consegue; a trava de outro indicador continua livre')]
    public function testSegundaConexaoNaoPassa(): void
    {
        $resultado = $this->indicacoes->emTransacaoTravada(901, function (): array {
            // Cada tentativa da outra conexão roda na própria transação e a abandona em seguida.
            $this->outra->beginTransaction();
            $mesmo = $this->conseguiuATrava(901);
            $this->outra->rollBack();

            $this->outra->beginTransaction();
            $outroIndicador = $this->conseguiuATrava(902);
            $this->outra->rollBack();

            return [$mesmo, $outroIndicador];
        });

        self::assertSame([false, true], $resultado, 'o mesmo indicador espera; outro indicador não');
    }

    #[TestDox('sem ninguém segurando, a segunda conexão consegue a trava (o teste anterior não é falso positivo)')]
    public function testLivreQuandoNinguemSegura(): void
    {
        $this->outra->beginTransaction();
        $conseguiu = $this->conseguiuATrava(903);
        $this->outra->rollBack();

        self::assertTrue($conseguiu);
    }
}
