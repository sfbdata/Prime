<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261019190000 as Migration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A carga da migration da C1 que deixa SEM LIMITE o escritório do dono (decisão do dono, 19/10/2026).
 *
 * O SQL da migration é executado aqui com outro id (o id 1 do banco de teste é de outro escritório): só a
 * combinação id E nome exato marca o escritório.
 */
#[CoversNothing]
final class ContribuaCargaDoEscritorioDoDonoTest extends KernelTestCase
{
    private const ARQUIVO_MIGRATION = __DIR__ . '/../../../migrations/Version20261019190000.php';

    #[TestDox('Id e nome exatos: o escritório fica sem limite; repetir a carga não duplica')]
    public function testIdENomeExatosMarcam(): void
    {
        $tenant = $this->criar(Migration::ESCRITORIO_DO_DONO_NOME);

        $this->carregar((int) $tenant->getId(), Migration::ESCRITORIO_DO_DONO_NOME);
        $this->carregar((int) $tenant->getId(), Migration::ESCRITORIO_DO_DONO_NOME);

        self::assertSame([['sem_limite' => true, 'codigo' => 'free']], $this->espacoDe($tenant));
    }

    #[TestDox('A carga grava a hora local do sistema (o NOW() do Postgres está em UTC, 3 h adiantado)')]
    public function testCargaNaHoraLocal(): void
    {
        $tenant = $this->criar(Migration::ESCRITORIO_DO_DONO_NOME);
        $this->carregar((int) $tenant->getId(), Migration::ESCRITORIO_DO_DONO_NOME);

        $gravado = new \DateTimeImmutable((string) static::getContainer()->get(Connection::class)->fetchOne('SELECT criado_em FROM contribua_espaco_do_escritorio WHERE tenant_id = ?', [$tenant->getId()]));
        self::assertLessThan(120, abs($gravado->getTimestamp() - time()), 'criado_em na hora do PHP (America/Sao_Paulo)');
    }

    #[TestDox('Mesmo id com outro nome não é marcado')]
    public function testOutroNomeNaoMarca(): void
    {
        $tenant = $this->criar('Prime Cobranças Administrativas');

        $this->carregar((int) $tenant->getId(), Migration::ESCRITORIO_DO_DONO_NOME);

        self::assertSame([], $this->espacoDe($tenant));
    }

    #[TestDox('Nome igual com outro id não é marcado')]
    public function testOutroIdNaoMarca(): void
    {
        $tenant = $this->criar(Migration::ESCRITORIO_DO_DONO_NOME);

        $this->carregar((int) $tenant->getId() + 100000, Migration::ESCRITORIO_DO_DONO_NOME);

        self::assertSame([], $this->espacoDe($tenant));
    }

    #[TestDox('A migration usa id 1 e o nome exato do escritório do dono')]
    public function testConstantesDaDecisao(): void
    {
        self::assertSame(1, Migration::ESCRITORIO_DO_DONO_ID);
        self::assertSame('Farlei Assis da Rocha Sociedade Individual de Advocacia', Migration::ESCRITORIO_DO_DONO_NOME);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        require_once self::ARQUIVO_MIGRATION;
    }

    private function criar(string $nome): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName($nome);
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function carregar(int $id, string $nome): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(Migration::SQL_ESCRITORIO_DO_DONO_SEM_LIMITE, ['id' => $id, 'nome' => $nome]);
    }

    /** @return list<array<string, mixed>> */
    private function espacoDe(Tenant $tenant): array
    {
        return static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT e.sem_limite, p.codigo FROM contribua_espaco_do_escritorio e JOIN contribua_plano p ON p.id = e.plano_id WHERE e.tenant_id = :t',
            ['t' => $tenant->getId()],
        );
    }
}
