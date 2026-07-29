<?php

declare(strict_types=1);

namespace App\Tests\AtualizacaoMonetaria\Functional;

use App\AtualizacaoMonetaria\Command\ImportarIndicesMonetariosCommand;
use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\AtualizacaoMonetaria\Service\ClienteSgsBcb;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(ImportarIndicesMonetariosCommand::class)]
final class ImportarIndicesMonetariosCommandTest extends KernelTestCase
{
    #[Test]
    public function importaAsTresSeriesDoBancoCentral(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, [
            '188' => [['data' => '01/01/1994', 'valor' => '41.32'], ['data' => '01/06/2026', 'valor' => '0.14']],
            '433' => [['data' => '01/06/2026', 'valor' => '0.16']],
            '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
        ]);

        $tester = $this->rodar();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        self::assertSame(2, \count($repositorio->findBy(['serie' => SerieIndice::INPC])));
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::IPCA])));
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::TAXA_LEGAL])));

        $inpc = $repositorio->findOneBy(['serie' => SerieIndice::INPC, 'competencia' => new \DateTimeImmutable('1994-01-01')]);
        self::assertInstanceOf(IndiceMonetario::class, $inpc);
        self::assertSame('41.320000', $inpc->getVariacaoPct());
        self::assertSame('BCB/SGS/188', $inpc->getFonte());
    }

    /**
     * Idempotência: o command roda em cron mensal e será reexecutado sobre dado já gravado.
     */
    #[Test]
    public function rodarDuasVezesNaoDuplicaNemAlteraNada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, [
            '188' => [['data' => '01/06/2026', 'valor' => '0.14']],
            '433' => [['data' => '01/06/2026', 'valor' => '0.16']],
            '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
        ]);

        self::assertSame(Command::SUCCESS, $this->rodar()->getStatusCode());

        $em = $container->get(EntityManagerInterface::class);
        $repositorio = $container->get(IndiceMonetarioRepository::class);
        $antes = $repositorio->findOneBy(['serie' => SerieIndice::INPC]);
        self::assertInstanceOf(IndiceMonetario::class, $antes);
        $carimboOriginal = $antes->getImportadoEm()->format('Y-m-d H:i:s');
        $em->clear();

        $segundaRodada = $this->rodar();

        self::assertSame(Command::SUCCESS, $segundaRodada->getStatusCode());
        self::assertSame(3, \count($repositorio->findAll()), 'a segunda rodada não pode duplicar');

        $depois = $repositorio->findOneBy(['serie' => SerieIndice::INPC]);
        self::assertInstanceOf(IndiceMonetario::class, $depois);
        self::assertSame('0.140000', $depois->getVariacaoPct());
        self::assertSame(
            $carimboOriginal,
            $depois->getImportadoEm()->format('Y-m-d H:i:s'),
            'sem revisão de índice, nem o carimbo pode mudar',
        );
        self::assertStringContainsString('0 revisão', $segundaRodada->getDisplay());
    }

    /**
     * A armadilha da spec §8: a API já devolveu lista vazia com o dado publicado. Uma importação
     * vazia não pode apagar nem zerar o que está gravado — e tem de ALARMAR o cron, senão o modo de
     * falha mais perigoso (tabela silenciosamente incompleta) passa como sucesso.
     */
    #[Test]
    public function respostaVaziaNaoApagaNemZeraAsSeriesJaImportadas(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->gravar($em, SerieIndice::INPC, '2026-06-01', '0.140000');
        $this->gravar($em, SerieIndice::IPCA, '2026-06-01', '0.160000');
        $em->clear();

        $this->injetarBcb($container, ['188' => [], '433' => [], '29543' => []]);

        $tester = $this->rodar();

        self::assertSame(Command::FAILURE, $tester->getStatusCode(), 'série vazia tem de alarmar o cron');

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        self::assertSame(2, \count($repositorio->findAll()), 'resposta vazia não pode apagar');

        $inpc = $repositorio->findOneBy(['serie' => SerieIndice::INPC]);
        self::assertInstanceOf(IndiceMonetario::class, $inpc);
        self::assertSame('0.140000', $inpc->getVariacaoPct(), 'resposta vazia não pode zerar');
    }

    #[Test]
    public function falhaEmUmaSerieNaoImpedeAsOutrasMasAlarmaOCron(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb(
            $container,
            [
                '188' => [['data' => '01/06/2026', 'valor' => '0.14']],
                '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
            ],
            statusPorSerie: ['433' => 500],
        );

        $tester = $this->rodar();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::INPC])));
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::TAXA_LEGAL])));
        self::assertSame(0, \count($repositorio->findBy(['serie' => SerieIndice::IPCA])));
    }

    /**
     * O IBGE revisa índice já publicado. A spec §8 exige que a sobrescrita seja REGISTRADA, não
     * silenciosa: um mês que muda depois altera dinheiro em simulação já impressa.
     */
    #[Test]
    public function revisaoDeIndiceJaGravadoEhAplicadaERegistrada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->gravar($em, SerieIndice::INPC, '2026-06-01', '0.140000');
        $em->clear();

        $this->injetarBcb($container, ['188' => [['data' => '01/06/2026', 'valor' => '0.19']], '433' => [], '29543' => []]);

        $tester = $this->rodar(['--serie' => 'INPC']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        $inpc = $repositorio->findOneBy(['serie' => SerieIndice::INPC]);
        self::assertInstanceOf(IndiceMonetario::class, $inpc);
        self::assertSame('0.190000', $inpc->getVariacaoPct());

        $saida = $tester->getDisplay();
        self::assertStringContainsString('2026-06', $saida, 'a competência revisada tem de aparecer');
        self::assertStringContainsString('0.140000', $saida, 'o valor anterior tem de aparecer');
        self::assertStringContainsString('0.190000', $saida, 'o valor novo tem de aparecer');
    }

    #[Test]
    public function dryRunNaoGravaNada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, [
            '188' => [['data' => '01/06/2026', 'valor' => '0.14']],
            '433' => [['data' => '01/06/2026', 'valor' => '0.16']],
            '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
        ]);

        $tester = $this->rodar(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, \count($container->get(IndiceMonetarioRepository::class)->findAll()));
    }

    #[Test]
    public function serieUnicaImportaSoAquela(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, [
            '188' => [['data' => '01/06/2026', 'valor' => '0.14']],
            '433' => [['data' => '01/06/2026', 'valor' => '0.16']],
            '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
        ]);

        $tester = $this->rodar(['--serie' => 'IPCA']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        self::assertSame(1, \count($repositorio->findAll()));
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::IPCA])));
    }

    #[Test]
    public function serieDesconhecidaEhRecusadaAntesDeQualquerChamada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $chamou = false;
        $container->set(ClienteSgsBcb::class, new ClienteSgsBcb(
            new MockHttpClient(function () use (&$chamou): MockResponse {
                $chamou = true;

                return new MockResponse('[]');
            }),
            'https://api.bcb.gov.br',
        ));

        $tester = $this->rodar(['--serie' => 'IGPM']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Contar linhas não provaria nada: o nome do teste promete que a rede não foi tocada.
        self::assertFalse($chamou, 'série inválida não pode chegar a consultar o BCB');
        self::assertSame(0, \count($container->get(IndiceMonetarioRepository::class)->findAll()));
    }

    /**
     * Lock ocupado tem de ALARMAR, não sair calado (achado A1 da revisão).
     *
     * Em cron mensal, um lockfile preso (processo morto sem liberar, container reiniciado no meio)
     * custa um mês inteiro de índice. `flock` é por descritor aberto: o handle que este teste segura
     * bloqueia o handle que o command abre, mesmo dentro do mesmo processo.
     */
    #[Test]
    public function lockOcupadoAbortaComFailureEmVezDeSairCalado(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, ['188' => [['data' => '01/06/2026', 'valor' => '0.14']]]);

        $caminho = sys_get_temp_dir() . '/' . ImportarIndicesMonetariosCommand::ARQUIVO_LOCK;
        $handle = fopen($caminho, 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB), 'o teste precisa segurar o lock primeiro');

        try {
            $tester = $this->rodar();

            self::assertSame(
                Command::FAILURE,
                $tester->getStatusCode(),
                'sair com SUCCESS aqui deixaria o cron mensal perder o mês sem ninguém saber',
            );
            self::assertSame(0, \count($container->get(IndiceMonetarioRepository::class)->findAll()));
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * `--dry-run` não pode nem AGENDAR insert, nem mutar entidade gerenciada (achado A4 da revisão).
     *
     * O teste anterior de dry-run rodava com a tabela vazia, então o ramo de revisão nunca era
     * exercitado — e o `findAll()` (que é SELECT) não enxergaria um `persist` sem `flush`. As duas
     * guardas se cobriam mutuamente. Aqui cada uma cai sozinha: a UnitOfWork denuncia o insert
     * agendado, e a entidade gerenciada em memória denuncia a revisão aplicada.
     */
    #[Test]
    public function dryRunNaoAgendaInsertNemMutaEntidadeJaGravada(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $this->gravar($em, SerieIndice::INPC, '2026-06-01', '0.140000');
        $em->clear();

        $existente = $container->get(IndiceMonetarioRepository::class)
            ->findOneBy(['serie' => SerieIndice::INPC, 'competencia' => new \DateTimeImmutable('2026-06-01')]);
        self::assertInstanceOf(IndiceMonetario::class, $existente);

        // 06/2026 revisado (0.14 → 0.19) e 05/2026 inédito: exercita os DOIS ramos sob dry-run.
        $this->injetarBcb($container, ['188' => [
            ['data' => '01/05/2026', 'valor' => '0.65'],
            ['data' => '01/06/2026', 'valor' => '0.19'],
        ]]);

        $tester = $this->rodar(['--serie' => 'INPC', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(
            '0.140000',
            $existente->getVariacaoPct(),
            'dry-run não pode aplicar a revisão nem na entidade em memória',
        );
        self::assertSame(
            [],
            $em->getUnitOfWork()->getScheduledEntityInsertions(),
            'dry-run não pode nem agendar o insert do índice novo',
        );
        // E continua CONTANDO certo — é o que faz o dry-run servir para alguma coisa.
        self::assertStringContainsString('1 novo(s), 1 revisão(ões)', $tester->getDisplay());
    }

    /**
     * Invariante violada numa série não pode descartar o que as outras já importaram (achado A3).
     *
     * O cliente recusa na fronteira o que não caberia em `numeric(12,6)`, então a série malformada
     * morre nele — mas o importante é o que acontece com as outras: a 188 vem antes e a 29543
     * depois, e as duas têm de sobreviver.
     */
    #[Test]
    public function valorImpossivelNumaSerieNaoDescartaAsSeriesJaImportadas(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->injetarBcb($container, [
            '188' => [['data' => '01/06/2026', 'valor' => '0.14']],
            '433' => [['data' => '01/06/2026', 'valor' => '0.1234567']],   // 7 casas: não cabe
            '29543' => [['data' => '01/07/2026', 'valor' => '0.706607']],
        ]);

        $tester = $this->rodar();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $repositorio = $container->get(IndiceMonetarioRepository::class);
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::INPC])), 'a série anterior tem de sobreviver');
        self::assertSame(1, \count($repositorio->findBy(['serie' => SerieIndice::TAXA_LEGAL])), 'a série seguinte tem de rodar');
        self::assertSame(0, \count($repositorio->findBy(['serie' => SerieIndice::IPCA])));
    }

    /**
     * Injeta um ClienteSgsBcb real por cima de um MockHttpClient: exercita o parsing de verdade
     * (que é onde mora a armadilha da API) sem tocar a rede.
     *
     * @param array<string, list<array{data: string, valor: string}>> $porSerie   código SGS => registros
     * @param array<string, int>                                      $statusPorSerie código SGS => status HTTP
     */
    private function injetarBcb(ContainerInterface $container, array $porSerie, array $statusPorSerie = []): void
    {
        $responder = function (string $metodo, string $url) use ($porSerie, $statusPorSerie): MockResponse {
            preg_match('/bcdata\.sgs\.(\d+)/', $url, $partes);
            $codigo = $partes[1] ?? '';

            if (isset($statusPorSerie[$codigo])) {
                return new MockResponse('erro', ['http_code' => $statusPorSerie[$codigo]]);
            }

            return new MockResponse((string) json_encode($porSerie[$codigo] ?? []));
        };

        $container->set(ClienteSgsBcb::class, new ClienteSgsBcb(new MockHttpClient($responder), 'https://api.bcb.gov.br'));
    }

    private function gravar(
        EntityManagerInterface $em,
        SerieIndice $serie,
        string $competencia,
        string $variacao,
    ): void {
        $em->persist(new IndiceMonetario(
            $serie,
            new \DateTimeImmutable($competencia),
            $variacao,
            $serie->fonte(),
            new \DateTimeImmutable('2026-01-01 00:00:00'),
        ));
        $em->flush();
    }

    /**
     * @param array<string, mixed> $args
     */
    private function rodar(array $args = []): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:importar-indices-monetarios'));
        $tester->execute($args);

        return $tester;
    }
}
