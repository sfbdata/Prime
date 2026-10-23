<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AndamentoDaPasta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261023150900;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O backfill de `pasta.finalizada_em` da migration `Version20261023150900` (spec dashboard-parte-1,
 * PR3): roda os dois SQL dela, na ordem da migration, sobre pastas montadas à mão, dentro da
 * transação do DAMA. Situação, andamento e fontes do arquivamento entram por SQL cru, para nenhum
 * listener inventar dado. A "última fase da trilha" esperada sai do próprio AndamentoDaPasta::fases(),
 * para o SQL não divergir do PHP.
 *
 * As migrations não estão no autoload do composer (o Doctrine as carrega por caminho), daí o
 * require_once.
 */
#[CoversNothing]
#[Group('dashboard')]
final class BackfillDaFinalizadaEmTest extends KernelTestCase
{
    use Factories;

    private const REATIVADA = [['Reativada', 'bi-arrow-repeat'], ['Em andamento', 'bi-hourglass-split'], ['Finalizada', 'bi-flag']];

    private Connection $banco;
    private Tenant $tenant;
    private User $autor;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../migrations/Version20261023150900.php';
    }

    protected function setUp(): void
    {
        $this->banco  = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->tenant = TenantFactory::createOne()->_real();
        $this->autor  = UserFactory::createOne()->_real();
    }

    #[TestDox('(a) Andamento da via atual na última fase da trilha (com as extras): a data do marco; outra fase, outra via ou reativada fica nula')]
    public function testPeloAndamento(): void
    {
        $finalizada = $this->pasta('ativo');
        $this->andamento($finalizada, 'judicial', 9, [0 => '2026-01-05T08:00:00-03:00', 9 => '2026-03-10T14:30:00-03:00']);

        $emAndamento = $this->pasta('arquivado');
        $this->andamento($emAndamento, 'judicial', 5, [5 => '2026-03-10T14:30:00-03:00']);
        $this->cartao($emAndamento, 'Ativo', 'Arquivado', '2026-03-11 10:00:00'); // tem trilho: o arquivamento não conta

        $administrativa = $this->pasta('ativo', administrativa: true);
        $this->andamento($administrativa, 'administrativa', 9, [9 => '2026-04-01T09:00:00-03:00']);

        $soNaOutraVia = $this->pasta('ativo', administrativa: true);
        $this->andamento($soNaOutraVia, 'judicial', 9, [9 => '2026-04-01T09:00:00-03:00']);

        $reativada = $this->pasta('ativo');
        $this->andamento($reativada, 'judicial', 11, [9 => '2026-02-01T09:00:00-03:00', 10 => '2026-05-01T09:00:00-03:00', 11 => '2026-05-01T09:00:00-03:00'], self::REATIVADA);

        $finalizadaDeNovo = $this->pasta('ativo');
        $this->andamento($finalizadaDeNovo, 'judicial', $this->ultima('judicial', self::REATIVADA), [9 => '2026-02-01T09:00:00-03:00', 12 => '2026-06-15T17:45:00-03:00'], self::REATIVADA);

        // "Reativada" sem o "Em andamento" gravado: a trilha do PHP tem uma fase a mais.
        $extraIncompleta = [['Reativada', 'bi-arrow-repeat'], ['Finalizada', 'bi-flag']];
        $incompleta = $this->pasta('ativo');
        $this->andamento($incompleta, 'judicial', $this->ultima('judicial', $extraIncompleta), [12 => '2026-07-01T10:00:00-03:00'], $extraIncompleta);
        $naoEhAUltima = $this->pasta('ativo');
        $this->andamento($naoEhAUltima, 'judicial', 11, [11 => '2026-07-01T10:00:00-03:00'], $extraIncompleta);

        $semMarco = $this->pasta('ativo');
        $this->andamento($semMarco, 'judicial', 9, [], atualizadoEm: '2026-08-08 08:08:08');

        $excluida = $this->pasta('arquivado', excluida: true);
        $this->andamento($excluida, 'judicial', 9, [9 => '2026-03-10T14:30:00-03:00']);

        $this->rodarBackfill();

        self::assertSame(12, $this->ultima('judicial', self::REATIVADA));
        self::assertSame(12, $this->ultima('judicial', $extraIncompleta));
        self::assertSame('2026-03-10 14:30:00', $this->finalizadaEm($finalizada), 'horário local do marco');
        self::assertNull($this->finalizadaEm($emAndamento), 'arquivada com trilho fora de Finalizada não conta');
        self::assertSame('2026-04-01 09:00:00', $this->finalizadaEm($administrativa));
        self::assertNull($this->finalizadaEm($soNaOutraVia), 'a trilha da outra via não conta');
        self::assertNull($this->finalizadaEm($reativada));
        self::assertSame('2026-06-15 17:45:00', $this->finalizadaEm($finalizadaDeNovo), 'reativada e finalizada de novo: a nova data');
        self::assertSame('2026-07-01 10:00:00', $this->finalizadaEm($incompleta));
        self::assertNull($this->finalizadaEm($naoEhAUltima));
        self::assertSame('2026-08-08 08:08:08', $this->finalizadaEm($semMarco), 'sem o marco, a última gravação');
        self::assertSame('2026-03-10 14:30:00', $this->finalizadaEm($excluida), 'a lápide não mexe no andamento; a contagem a tira pelo excluida_em');
    }

    #[TestDox('(b) Arquivada sem andamento na via atual: o arquivamento mais recente entre o Registro e a auditoria; sem fonte fica nula e é contada; excluída e ativa ficam nulas')]
    public function testPeloArquivamento(): void
    {
        $semFonteAntes = (int) $this->banco->fetchOne(Version20261023150900::SQL_CONTA_SEM_FONTE);

        $peloRegistro = $this->pasta('arquivado');
        $this->cartao($peloRegistro, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');
        $this->cartao($peloRegistro, 'Arquivado', 'Ativo', '2026-05-02 10:00:00');
        $this->cartao($peloRegistro, 'Ativo', 'Arquivado', '2026-05-03 10:00:00'); // o último que arquivou
        $this->auditoria($peloRegistro, '{"diff":{"changes":{"situacao":{"from":"ativo","to":"arquivado"}}}}', '2026-04-01 08:00:00');

        $pelaAuditoria = $this->pasta('arquivado');
        $this->auditoria($pelaAuditoria, '{"diff":{"changes":{"situacao":{"from":"suspenso","to":"arquivado"}}}}', '2026-06-10 09:30:00');
        $this->auditoria($pelaAuditoria, '{"diff":{"changes":{"situacao":{"from":"arquivado","to":"ativo"}}}}', '2026-06-11 09:30:00');

        // Arquivada pelo "Editar dados" antigo (sem cartão) depois de um cartão antigo: vale a auditoria.
        $modalDepois = $this->pasta('arquivado');
        $this->cartao($modalDepois, 'Ativo', 'Arquivado', '2026-03-01 10:00:00');
        $this->auditoria($modalDepois, '{"diff":{"changes":{"situacao":{"from":"ativo","to":"arquivado"}}}}', '2026-07-01 10:00:00');

        // Administrativa com trilho só na via judicial: sem andamento na via atual, vale o arquivamento.
        $outraVia = $this->pasta('arquivado', administrativa: true);
        $this->andamento($outraVia, 'judicial', 5, [5 => '2026-03-10T14:30:00-03:00']);
        $this->cartao($outraVia, 'Ativo', 'Arquivado', '2026-08-01 10:00:00');

        $semFonte = $this->pasta('arquivado');
        $this->cartao($semFonte, 'Ativo', 'Suspenso', '2026-05-01 10:00:00');

        $excluida = $this->pasta('arquivado', excluida: true);
        $this->cartao($excluida, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');

        $ativa = $this->pasta('ativo');
        $this->cartao($ativa, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');

        self::assertSame($semFonteAntes + 1, (int) $this->banco->fetchOne(Version20261023150900::SQL_CONTA_SEM_FONTE));

        $this->rodarBackfill();

        self::assertSame('2026-05-03 10:00:00', $this->finalizadaEm($peloRegistro));
        self::assertSame('2026-06-10 09:30:00', $this->finalizadaEm($pelaAuditoria));
        self::assertSame('2026-07-01 10:00:00', $this->finalizadaEm($modalDepois));
        self::assertSame('2026-08-01 10:00:00', $this->finalizadaEm($outraVia));
        self::assertNull($this->finalizadaEm($semFonte));
        self::assertNull($this->finalizadaEm($excluida), 'pasta excluída não conta');
        self::assertNull($this->finalizadaEm($ativa));
    }

    private function rodarBackfill(): void
    {
        $this->banco->executeStatement(Version20261023150900::SQL_BACKFILL_PELO_ANDAMENTO);
        $this->banco->executeStatement(Version20261023150900::SQL_BACKFILL_PELO_ARQUIVAMENTO);
    }

    /** @param list<array{0: string, 1: string}> $extra */
    private function ultima(string $via, array $extra): int
    {
        return count(AndamentoDaPasta::fases($via, $extra)) - 1;
    }

    private function pasta(string $situacao, bool $excluida = false, bool $administrativa = false): int
    {
        $id = (int) PastaFactory::createOne(['tenant' => $this->tenant])->_real()->getId();
        $this->banco->executeStatement(
            'UPDATE pasta SET situacao = ?, finalizada_em = NULL, excluida_em = ?, administrativa = ? WHERE id = ?',
            [$situacao, $excluida ? '2026-08-01 00:00:00' : null, $administrativa ? 'true' : 'false', $id],
        );

        return $id;
    }

    /**
     * @param array<int, string>                $em
     * @param list<array{0: string, 1: string}> $extra
     */
    private function andamento(int $pasta, string $via, int $atual, array $em, array $extra = [], string $atualizadoEm = '2026-09-09 09:09:09'): void
    {
        $marcos = json_encode(['em' => (object) $em, 'pul' => [], 'extra' => $extra], JSON_THROW_ON_ERROR);
        $this->banco->executeStatement(
            'INSERT INTO pasta_andamento (atual, marcos, atualizado_em, passou_protocolo, via, tenant_id, pasta_id) VALUES (?, ?, ?, false, ?, ?, ?)',
            [$atual, $marcos, $atualizadoEm, $via, $this->tenant->getId(), $pasta],
        );
    }

    /** O cartão do sistema como o RegistroDoSistemaNaPasta grava (aspas em entidade HTML). */
    private function cartao(int $pasta, string $de, string $para, string $em): void
    {
        $conteudo = '<p><strong>Situação da pasta:</strong> alterada de &quot;' . $de . '&quot; para &quot;' . $para . '&quot;.</p>';
        $this->banco->executeStatement(
            "INSERT INTO pasta_mensagem (conteudo, criada_em, pasta_id, autor_id, tenant_id, eh_resposta, sistema) VALUES (?, ?, ?, ?, ?, false, 'situacao')",
            [$conteudo, $em, $pasta, $this->autor->getId(), $this->tenant->getId()],
        );
    }

    private function auditoria(int $pasta, string $mudancas, string $em): void
    {
        $this->banco->executeStatement(
            'INSERT INTO audit_log (action, entity_class, entity_id, changes, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['update', Pasta::class, (string) $pasta, $mudancas, $this->tenant->getId(), $em],
        );
    }

    private function finalizadaEm(int $pasta): ?string
    {
        $valor = $this->banco->fetchOne('SELECT finalizada_em FROM pasta WHERE id = ?', [$pasta]);

        return $valor === null ? null : (string) $valor;
    }
}
