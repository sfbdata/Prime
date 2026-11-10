<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O gatilho `registro_ponto_so_insercao` (E10, migration Version20261109280000), direto no banco: a batida gravada não
 * muda coluna nenhuma (sem exceção) e só se apaga na purga do próprio escritório
 * (`set_config('bluejus.purga_do_escritorio', id, true)`). Por isso apagar a sede que tem batida também é recusado:
 * a FK ON DELETE SET NULL tentaria mudar a batida.
 *
 * Cada comando recusado roda num ponto de salvamento (transação aninhada da DBAL sobre a do DAMA): o erro do banco
 * aborta só ele, e o `set_config` local some junto no rollback.
 */
#[CoversClass(RegistroPontoRepository::class)]
final class RegistroPontoSoInsercaoNoBancoTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conexao;
    private Tenant $escritorio;
    private User $pessoa;
    private RegistroPonto $batida;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = $this->em->getConnection();
        $this->escritorio = $this->escritorio('A');
        $this->pessoa = $this->pessoa('Pessoa do gatilho');
        $this->batida = $this->batida($this->escritorio, $this->pessoa);
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function colunasTravadas(): iterable
    {
        yield 'data e hora' => ["UPDATE registro_ponto SET data_hora = data_hora - INTERVAL '1 hour' WHERE id = ?", 0];
        yield 'tipo' => ["UPDATE registro_ponto SET tipo = 'saida' WHERE id = ?", 0];
        yield 'pessoa' => ['UPDATE registro_ponto SET user_id = ? WHERE id = ?', 'outra pessoa'];
        yield 'escritório' => ['UPDATE registro_ponto SET tenant_id = ? WHERE id = ?', 'outro escritório'];
        yield 'observação' => ["UPDATE registro_ponto SET observacao = 'nota' WHERE id = ?", 0];
        yield 'nome da sede' => ["UPDATE registro_ponto SET sede_nome_snapshot = 'Centro' WHERE id = ?", 0];
        yield 'sede' => ['UPDATE registro_ponto SET sede_id = ? WHERE id = ?', 'uma sede'];
        yield 'home office' => ['UPDATE registro_ponto SET home_office = true WHERE id = ?', 0];
        yield 'sem internet' => ['UPDATE registro_ponto SET enviada_sem_internet = true WHERE id = ?', 0];
        yield 'posição' => ["UPDATE registro_ponto SET latitude = '-23.5', longitude = '-46.6' WHERE id = ?", 0];
    }

    #[DataProvider('colunasTravadas')]
    #[TestDox('UPDATE de $_dataName na batida gravada é recusado com a mensagem em português')]
    public function testUpdateDaColunaTravadaRecusado(string $sql, int|string $novo): void
    {
        $parametros = match ($novo) {
            'outra pessoa'     => [$this->pessoa('Outra pessoa')->getId(), $this->batida->getId()],
            'outro escritório' => [$this->escritorio('B')->getId(), $this->batida->getId()],
            'uma sede'         => [$this->sede()->getId(), $this->batida->getId()],
            default            => [$this->batida->getId()],
        };

        $erro = $this->recusado($sql, $parametros);

        self::assertStringContainsString('A batida gravada do ponto não muda', $erro);
        self::assertSame('2026-10-21 08:00:00', $this->conexao->fetchOne('SELECT data_hora FROM registro_ponto WHERE id = ?', [$this->batida->getId()]));
    }

    #[TestDox('UPDATE que repete os mesmos valores passa (IS DISTINCT FROM na linha inteira)')]
    public function testUpdateSemMudarColunaTravadaPassa(): void
    {
        $alteradas = $this->conexao->executeStatement(
            'UPDATE registro_ponto SET data_hora = data_hora, tipo = tipo, observacao = observacao, sede_id = sede_id WHERE id = ?',
            [$this->batida->getId()],
        );

        self::assertSame(1, $alteradas);
        self::assertNull($this->conexao->fetchOne('SELECT observacao FROM registro_ponto WHERE id = ?', [$this->batida->getId()]));
    }

    #[TestDox('Apagar a sede que tem batida é recusado: a FK ON DELETE SET NULL esbarra no gatilho')]
    public function testApagarSedeComBatidaRecusado(): void
    {
        $sede = $this->sede();
        $comSede = (new RegistroPonto())
            ->setTenant($this->escritorio)
            ->setUser($this->pessoa)
            ->setSede($sede)
            ->setTipo(RegistroPonto::TIPO_SAIDA)
            ->setDataHora(new \DateTime('2026-10-21 18:00:00'));
        $this->em->persist($comSede);
        $this->em->flush();

        $erro = $this->recusado('DELETE FROM sede WHERE id = ?', [$sede->getId()]);

        self::assertStringContainsString('A batida gravada do ponto não muda', $erro);
        self::assertSame($sede->getId(), (int) $this->conexao->fetchOne('SELECT sede_id FROM registro_ponto WHERE id = ?', [$comSede->getId()]));
    }

    #[TestDox('DELETE da batida gravada é recusado')]
    public function testDeleteRecusado(): void
    {
        $erro = $this->recusado('DELETE FROM registro_ponto WHERE id = ?', [$this->batida->getId()]);

        self::assertStringContainsString('A batida gravada do ponto não pode ser apagada', $erro);
        self::assertSame(1, $this->quantasDoEscritorio($this->escritorio));
    }

    #[TestDox('DELETE com o escape da purga do MESMO escritório passa')]
    public function testDeleteComEscapeDoMesmoEscritorioPassa(): void
    {
        $this->conexao->beginTransaction();
        try {
            $this->conexao->executeQuery("SELECT set_config('bluejus.purga_do_escritorio', ?, true)", [(string) $this->escritorio->getId()]);
            $apagadas = $this->conexao->executeStatement('DELETE FROM registro_ponto WHERE tenant_id = ?', [$this->escritorio->getId()]);
            $restantes = $this->quantasDoEscritorio($this->escritorio);
        } finally {
            $this->conexao->rollBack();
        }

        self::assertSame(1, $apagadas);
        self::assertSame(0, $restantes);
    }

    #[TestDox('DELETE com o escape da purga de OUTRO escritório é recusado; com o escape vazio também')]
    public function testDeleteComEscapeDeOutroEscritorioRecusado(): void
    {
        $outro = $this->escritorio('C');

        foreach ([(string) $outro->getId(), ''] as $escape) {
            $this->conexao->beginTransaction();
            try {
                $this->conexao->executeQuery("SELECT set_config('bluejus.purga_do_escritorio', ?, true)", [$escape]);
                $erro = $this->recusado('DELETE FROM registro_ponto WHERE id = ?', [$this->batida->getId()]);
            } finally {
                $this->conexao->rollBack();
            }
            self::assertStringContainsString('não pode ser apagada', $erro, sprintf('escape "%s"', $escape));
        }
        self::assertSame(1, $this->quantasDoEscritorio($this->escritorio));
    }

    /**
     * Roda o comando num ponto de salvamento e devolve a mensagem do erro do banco; falha o teste se o banco aceitar.
     *
     * @param list<mixed> $parametros
     */
    private function recusado(string $sql, array $parametros): string
    {
        $this->conexao->beginTransaction();
        try {
            $this->conexao->executeStatement($sql, $parametros);
        } catch (DriverException $erro) {
            $this->conexao->rollBack();

            return $erro->getMessage();
        }
        $this->conexao->rollBack();
        self::fail('O banco aceitou: ' . $sql);
    }

    private function quantasDoEscritorio(Tenant $escritorio): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM registro_ponto WHERE tenant_id = ?', [$escritorio->getId()]);
    }

    private function sede(): Sede
    {
        $sede = (new Sede())->setTenant($this->escritorio)->setNome('Sede do gatilho')->setLatitude('-23.55')->setLongitude('-46.63');
        $this->em->persist($sede);
        $this->em->flush();

        return $sede;
    }

    private function batida(Tenant $escritorio, User $pessoa): RegistroPonto
    {
        $batida = (new RegistroPonto())
            ->setTenant($escritorio)
            ->setUser($pessoa)
            ->setTipo(RegistroPonto::TIPO_ENTRADA)
            ->setDataHora(new \DateTime('2026-10-21 08:00:00'));
        $this->em->persist($batida);
        $this->em->flush();

        return $batida;
    }

    private function pessoa(string $nome): User
    {
        $pessoa = (new User())->setEmail('gatilho_' . uniqid() . '@test.com')->setFullName($nome);
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setPassword('x');
        $this->em->persist($pessoa);
        $this->em->flush();

        return $pessoa;
    }

    private function escritorio(string $nome): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório do gatilho ' . $nome . ' ' . uniqid());
        $this->em->persist($escritorio);
        $this->em->flush();

        return $escritorio;
    }
}
