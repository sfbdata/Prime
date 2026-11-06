<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * GET /expediente: quantas consultas a página faz, para o número não voltar a subir.
 *
 * - Os submarcadores do trilho vêm na consulta dos marcadores (antes, uma por marcador de topo), na ordem do
 *   mapeamento (`ordem`, `nome`).
 * - As preferências da pessoa saem numa consulta só (antes, uma por chave pedida pelo layout).
 * - O total não cresce com o número de marcadores.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteQueriesDaPaginaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    /** Teto medido em 06/11/2026 neste cenário: subir exige motivo. */
    private const TETO = ['primario' => 35, 'colaborador' => 31];

    /** @return iterable<string, array{string}> */
    public static function personas(): iterable
    {
        yield 'primário' => ['primario'];
        yield 'colaborador' => ['colaborador'];
    }

    #[DataProvider('personas')]
    #[TestDox('$_dataName: uma consulta de marcadores e uma de preferências; total no teto e igual com 1 ou 4 marcadores')]
    public function testConsultasDaPagina(string $persona): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $quem = ['primario' => $primario, 'colaborador' => $this->criarColaborador($tenant)][$persona];
        $this->criarPasta($tenant);

        $this->criarArvore($tenant, $primario, 'A');
        // O primeiro perfil do cliente leva junto as consultas da montagem do cenário: só aquece.
        $this->abrir($quem, $tenant);
        [$sqlsCom1] = $this->abrir($quem, $tenant);

        $this->criarArvore($tenant, $primario, 'B');
        $this->criarArvore($tenant, $primario, 'C');
        $this->criarArvore($tenant, $primario, 'D');
        [$sqls, $crawler] = $this->abrir($quem, $tenant);

        self::assertSame(1, $this->contar($sqls, '/\bFROM marcador m0_ /'), implode("\n", $sqls));
        self::assertSame(0, $this->contar($sqls, '/FROM marcador t0 WHERE t0\.pai_id = \?/'), 'submarcador lido um a um');
        self::assertSame(1, $this->contar($sqls, '/FROM preferencia_usuario /'), implode("\n", $sqls));
        self::assertCount(\count($sqlsCom1), $sqls, 'o total cresceu com o número de marcadores');
        self::assertLessThanOrEqual(self::TETO[$persona], \count($sqls), implode("\n", $sqls));

        // O trilho continua igual: os 4 de topo, cada um com os 2 filhos na ordem do mapeamento (ordem, nome).
        foreach (['A', 'B', 'C', 'D'] as $letra) {
            $grupo = $crawler->filter('.exp-tri-grupo')->reduce(
                static fn (Crawler $g): bool => $g->filter('.exp-tri-marcador:not(.exp-tri-sub)')->attr('data-nome') === 'Topo ' . $letra,
            );
            self::assertCount(1, $grupo, 'Topo ' . $letra);
            self::assertSame('2', $grupo->filter('.exp-tri-marcador:not(.exp-tri-sub)')->attr('data-filhos'));
            self::assertSame(
                ['Sub ' . $letra . ' primeiro', 'Sub ' . $letra . ' segundo'],
                $grupo->filter('.exp-tri-sub')->each(static fn (Crawler $s): string => (string) $s->attr('data-nome')),
            );
        }
    }

    /** Um marcador de topo com 2 filhos; o criado primeiro tem `ordem` maior, para provar a ordem do mapeamento. */
    private function criarArvore(Tenant $tenant, User $por, string $letra): void
    {
        // A medição limpa o EntityManager: pega as referências de novo.
        $tenant = $this->em()->getReference(Tenant::class, $tenant->getId());
        $por = $this->em()->getReference(User::class, $por->getId());
        $topo = new Marcador('Topo ' . $letra, $tenant, $por);
        $this->em()->persist($topo);
        $segundo = (new Marcador('Sub ' . $letra . ' segundo', $tenant, $por, $topo))->setOrdem(2);
        $primeiro = (new Marcador('Sub ' . $letra . ' primeiro', $tenant, $por, $topo))->setOrdem(1);
        $this->em()->persist($segundo);
        $this->em()->persist($primeiro);
        $this->em()->flush();
    }

    /** @return array{0: list<string>, 1: Crawler} */
    private function abrir(User $quem, Tenant $tenant): array
    {
        $this->entrar($quem, $tenant);
        $this->em()->clear();
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        $db = $this->client->getProfile()->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $db);

        return [array_values(array_column($db->getQueries()['default'] ?? [], 'sql')), $crawler];
    }

    /** @param list<string> $sqls */
    private function contar(array $sqls, string $forma): int
    {
        return \count(array_filter($sqls, static fn (string $sql): bool => preg_match($forma, $sql) === 1));
    }
}
