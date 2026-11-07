<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HistoricoDeJornadas;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A migration `Version20261107100000` (spec `docs/specs/ponto-jornada-vigencia.md` §4) não muda a folha de ninguém.
 *
 * "Antes" é a folha como ela era calculada até aqui: UMA jornada aplicada a todos os dias. "Depois" é a folha pelo
 * histórico de versões que a migration deixa no banco (a jornada vira uma versão com a data do primeiro registro). As
 * duas têm de ser idênticas, linha a linha, e o saldo do ano também.
 *
 * Roda dentro da transação do DAMA: o PostgreSQL desfaz a DDL no fim, e o banco volta como estava.
 */
#[CoversClass(HistoricoDeJornadas::class)]
#[CoversClass(JornadaColaboradorRepository::class)]
final class MigracaoDaJornadaComVigenciaTest extends KernelTestCase
{
    private const VERSAO = 'DoctrineMigrations\\Version20261107100000';

    #[TestDox('A folha e o saldo do ano são os mesmos antes e depois da migration')]
    public function testFolhaIgualAntesEDepoisDaMigration(): void
    {
        self::bootKernel();
        $ano = (int) (new \DateTimeImmutable())->format('Y') - 1;
        [$pessoa, $escritorio, $jornada] = $this->pessoaComJornadaDe44Horas();
        $primeiroRegistro = $this->registrosDeMarcoEAbril($pessoa, $escritorio, $ano);
        [$semRegistro, $escritorioSemRegistro] = $this->pessoaComJornadaDe44Horas();
        $criadaEm = sprintf('%04d-05-20', $ano);
        $this->criacaoNoAuditLog($semRegistro, $escritorioSemRegistro, $criadaEm);

        // A contagem começa ANTES do primeiro registro (como no abono anterior à primeira batida,
        // `InicioContagemResolver`): fevereiro inteiro conta e fica antes da data que a versão vai ganhar.
        $inicioContagem = new \DateTimeImmutable(sprintf('%04d-02-01', $ano));

        // "Antes": o cálculo de até aqui, UMA jornada aplicada a todos os dias.
        $antes = $this->folhaDoTrimestre($pessoa, $escritorio, $ano, $jornada, $inicioContagem);
        $saldoAntes = $this->construtorComUmaJornadaSo($jornada)->calcularSaldoAnual($pessoa, $ano, [], null, $inicioContagem, $escritorio);
        self::assertNotSame(0, $saldoAntes, 'o cenário precisa de saldo, senão a comparação não prova nada');

        self::assertSame(0, $this->executar('--down'));
        self::assertFalse($this->coluna('vale_a_partir_de'));
        $this->reiniciarKernel();
        self::assertSame(0, $this->executar('--up'));
        $this->reiniciarKernel();

        $pessoa = $this->em()->find(User::class, $pessoa->getId());
        $escritorio = $this->em()->find(Tenant::class, $escritorio->getId());
        self::assertNotNull($pessoa);
        self::assertNotNull($escritorio);
        $historico = $this->servico(JornadaColaboradorRepository::class)->historicoDe($pessoa, $escritorio);
        self::assertNotNull($historico);
        self::assertCount(1, $historico->versoes(), 'cada jornada vira uma versão só');
        self::assertSame($primeiroRegistro, $historico->versoes()[0]->getValeAPartirDe()->format('Y-m-d'), 'a versão começa no primeiro registro');

        // "Depois": o histórico que a migration deixou no banco, pelo serviço de verdade.
        self::assertEquals($antes, $this->folhaDoTrimestre($pessoa, $escritorio, $ano, $historico, $inicioContagem));
        self::assertSame($saldoAntes, $this->servico(FolhaPontoBuilder::class)->calcularSaldoAnual($pessoa, $ano, [], null, $inicioContagem, $escritorio));

        self::assertSame(
            $criadaEm,
            $this->banco()->fetchOne('SELECT vale_a_partir_de FROM jornada_colaborador WHERE user_id = ?', [$semRegistro->getId()]),
            'sem registro de ponto, a versão começa no dia em que a jornada foi criada (audit_log)',
        );
        self::assertSame(1, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'uq_jornada_colaborador_vigencia'"));
        self::assertSame(0, (int) $this->banco()->fetchOne("SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'uq_jornada_colaborador_user_tenant'"));
    }

    #[TestDox('O down recusa quando alguém já tem mais de uma versão, para não apagar histórico')]
    public function testDownRecusaQuandoHaVersoes(): void
    {
        self::bootKernel();
        [$pessoa, $escritorio] = $this->pessoaComJornadaDe44Horas();
        $anterior = (new JornadaColaborador())
            ->setUser($pessoa)
            ->setTenant($escritorio)
            ->setValeAPartirDe(new \DateTimeImmutable('2026-04-01'));
        $this->em()->persist($anterior);
        $this->em()->flush();

        try {
            $this->executar('--down');
            self::fail('o down tinha de recusar');
        } catch (AbortMigration $recusa) {
            self::assertStringContainsString('voltar apagaria o histórico', $recusa->getMessage());
        }
        self::assertTrue($this->coluna('vale_a_partir_de'), 'a coluna continua lá');
    }

    /** @return array{0: User, 1: Tenant, 2: JornadaColaborador} 9h de segunda a quinta e 8h na sexta */
    private function pessoaComJornadaDe44Horas(): array
    {
        $escritorio = (new Tenant())->setName('Escritório vigência ' . uniqid());
        $pessoa = new User();
        $pessoa->setEmail('vigencia_' . uniqid() . '@teste.com');
        $pessoa->setFullName('Pessoa da vigência');
        $pessoa->setRoles(['ROLE_USER']);
        $pessoa->setIsActive(true);

        $jornada = (new JornadaColaborador())->setUser($pessoa)->setTenant($escritorio)->setCargaHorariaSemanal(2640);
        foreach ([[[1, 2, 3, 4], '18:00', 540], [[5], '17:00', 480]] as [$dias, $saida, $minutos]) {
            $jornada->addBloco(
                (new BlocoJornadaColaborador())
                    ->setDiasSemana($dias)
                    ->setEntrada('08:00')
                    ->setRepouso('12:00')
                    ->setRetorno('13:00')
                    ->setSaida($saida)
                    ->setMinutosBloco($minutos)
            );
        }

        $this->em()->persist($escritorio);
        $this->em()->persist($pessoa);
        $this->em()->persist($jornada);
        $this->em()->flush();

        return [$pessoa, $escritorio, $jornada];
    }

    /** Um dia útil a cada três, de março a abril, com saídas variadas. @return string o dia do primeiro registro */
    private function registrosDeMarcoEAbril(User $pessoa, Tenant $escritorio, int $ano): string
    {
        $saidas = ['16:30', '17:00', '18:15', '18:00', '19:05'];
        $primeiro = null;
        $n = 0;
        for ($dia = new \DateTimeImmutable("{$ano}-03-02"); $dia <= new \DateTimeImmutable("{$ano}-04-30"); $dia = $dia->modify('+3 days')) {
            if ((int) $dia->format('N') >= 6) {
                continue;
            }
            $primeiro ??= $dia->format('Y-m-d');
            $saida = $saidas[$n++ % count($saidas)];
            foreach ([RegistroPonto::TIPO_ENTRADA => '08:00', RegistroPonto::TIPO_REPOUSO => '12:00', RegistroPonto::TIPO_RETORNO => '13:00', RegistroPonto::TIPO_SAIDA => $saida] as $tipo => $hora) {
                $registro = new RegistroPonto();
                $registro->setUser($pessoa);
                $registro->setTenant($escritorio);
                $registro->setTipo($tipo);
                $registro->setDataHora(new \DateTime($dia->format('Y-m-d') . ' ' . $hora));
                $this->em()->persist($registro);
            }
        }
        $this->em()->flush();
        self::assertNotNull($primeiro);

        return $primeiro;
    }

    /**
     * O mesmo serviço de saldo, mas com a jornada solta valendo para todos os dias: é o cálculo de antes das versões.
     * O repositório de jornadas é nosso e pode ser trocado por um dublê (tests/CLAUDE.md).
     */
    private function construtorComUmaJornadaSo(JornadaColaborador $jornada): FolhaPontoBuilder
    {
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('historicoDe')->willReturn(HistoricoDeJornadas::deUma($jornada));

        return new FolhaPontoBuilder(
            $this->servico(CalculadoraJornada::class),
            $this->servico(RegistroPontoRepository::class),
            $this->servico(JustificativaPontoRepository::class),
            $this->servico(LancamentoHorasPagasRepository::class),
            $jornadas,
        );
    }

    /** Um `create` antigo da jornada, no formato que o `AuditLogSubscriber` grava. */
    private function criacaoNoAuditLog(User $pessoa, Tenant $escritorio, string $dia): void
    {
        $this->banco()->executeStatement(
            "INSERT INTO audit_log (id, action, entity_class, changes, tenant_id, created_at)
             VALUES (nextval('audit_log_id_seq'), 'create', 'App\\Ponto\\Entity\\JornadaColaborador', ?, ?, ?)",
            [
                json_encode(['diff' => ['after' => ['user' => ['class' => User::class, 'id' => (string) $pessoa->getId(), 'label' => null]]]], JSON_THROW_ON_ERROR),
                $escritorio->getId(),
                $dia . ' 10:00:00',
            ],
        );
    }

    /** @return list<array<int, array<string, mixed>>> as linhas de fevereiro, março e abril */
    private function folhaDoTrimestre(User $pessoa, Tenant $escritorio, int $ano, JornadaColaborador|HistoricoDeJornadas $jornada, ?\DateTimeInterface $inicioContagem): array
    {
        $folha = [];
        foreach ([2, 3, 4] as $mes) {
            $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
            $folha[] = $this->servico(FolhaPontoBuilder::class)->buildRows(
                $inicio,
                $inicio->modify('last day of this month')->setTime(23, 59, 59),
                $this->servico(RegistroPontoRepository::class)->findByUserAndCompetencia($pessoa, $escritorio, $ano, $mes),
                true,
                false,
                $jornada,
                [],
                [],
                null,
                $inicioContagem,
            );
        }

        return $folha;
    }

    private function executar(string $sentido): int
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('doctrine:migrations:execute'));

        return $tester->execute(['versions' => [self::VERSAO], $sentido => true], ['interactive' => false]);
    }

    /** A migration executada fica congelada na fábrica de dependências; a conexão do DAMA é estática e segue a mesma. */
    private function reiniciarKernel(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
    }

    private function coluna(string $coluna): bool
    {
        return (bool) $this->banco()->fetchOne(
            "SELECT 1 FROM information_schema.columns WHERE table_name = 'jornada_colaborador' AND column_name = ?",
            [$coluna],
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function servico(string $classe): object
    {
        /** @var T $servico */
        $servico = static::getContainer()->get($classe);

        return $servico;
    }

    private function em(): EntityManagerInterface
    {
        return $this->servico(EntityManagerInterface::class);
    }

    private function banco(): Connection
    {
        return $this->em()->getConnection();
    }
}
