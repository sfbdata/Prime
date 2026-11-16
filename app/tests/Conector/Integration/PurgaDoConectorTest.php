<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A purga do escritório leva as 6 tabelas do conector que têm escritório; o cliente OAuth, que é global, e tudo de
 * outro escritório ficam (`docs/specs/mcp-remoto-oauth.md`, 13.2: "Toda tabela com escritório entra no
 * PurgarEscritorioUseCase").
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaDoConectorTest extends KernelTestCase
{
    use Factories;
    use SemeiaConector;

    private const TABELAS_COM_ESCRITORIO = [
        'conector_ia_proposta', 'conector_ia_token', 'conector_ia_codigo', 'conector_ia_concessao', 'conector_ia_consentimento', 'conector_ia_escritorio',
    ];

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    #[TestDox('A purga apaga as 6 tabelas do conector do escritório e não toca o cliente global nem o outro escritório')]
    public function testPurgaApagaConectorDoEscritorio(): void
    {
        $user = UserFactory::createOne()->_real();
        $global = $this->clienteGlobal();
        $purgado = $this->tenantNovo(ativo: false, excluidoEm: '-400 days');
        $vizinho = $this->tenantNovo();
        $this->semearConector($purgado, $user, $global);
        $this->semearConector($vizinho, $user, $global);
        $idPurgado = (int) $purgado->getId();
        $idVizinho = (int) $vizinho->getId();
        $antesDoVizinho = $this->contarConector($idVizinho);
        self::assertSame([1, 4, 1, 1, 1, 1], array_values($antesDoVizinho), 'sanidade: o vizinho tem uma linha em cada tabela (quatro tokens: dois pares)');

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($purgado, dryRun: false);
        $this->em->clear();

        self::assertSame([0, 0, 0, 0, 0, 0], array_values($this->contarConector($idPurgado)), 'nada do conector sobra no escritório purgado');
        self::assertSame(4, $resultado->linhasPorTabela['conector_ia_token'] ?? 0, 'os tokens saem pelo DELETE explícito, não só pelo CASCADE');
        self::assertSame(1, $resultado->linhasPorTabela['conector_ia_concessao'] ?? 0);
        self::assertSame($antesDoVizinho, $this->contarConector($idVizinho), 'nenhuma linha do vizinho muda');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM conector_ia_cliente WHERE id = ?', [$global->getId()]), 'o cliente global fica');
    }

    /** @return array<string, int> na ordem de TABELAS_COM_ESCRITORIO */
    private function contarConector(int $tenantId): array
    {
        $contagens = [];
        foreach (self::TABELAS_COM_ESCRITORIO as $tabela) {
            $contagens[$tabela] = (int) $this->conn->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id = ?', $tabela), [$tenantId]);
        }

        return $contagens;
    }
}
