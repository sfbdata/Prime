<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
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
 * O backfill de `pasta.arquivada_em` da migration `Version20261023150900` (spec dashboard-parte-1,
 * PR3): roda o SQL dela sobre pastas montadas à mão, dentro da transação do DAMA. A situação e as
 * fontes (cartão do Registro e auditoria) entram por SQL cru, para nenhum listener inventar fonte.
 *
 * As migrations não estão no autoload do composer (o Doctrine as carrega por caminho), daí o
 * require_once.
 */
#[CoversNothing]
#[Group('dashboard')]
final class BackfillDaArquivadaEmTest extends KernelTestCase
{
    use Factories;

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

    #[TestDox('Vale o arquivamento mais recente entre o Registro e a auditoria; sem fonte fica nula e é contada; excluída e ativa ficam nulas')]
    public function testBackfill(): void
    {
        $semFonteAntes = (int) $this->banco->fetchOne(Version20261023150900::SQL_CONTA_SEM_FONTE);

        $peloRegistro = $this->pasta('arquivado');
        $this->cartao($peloRegistro, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');
        $this->cartao($peloRegistro, 'Arquivado', 'Ativo', '2026-05-02 10:00:00');
        $this->cartao($peloRegistro, 'Ativo', 'Arquivado', '2026-05-03 10:00:00'); // o último que arquivou
        $this->auditoria($peloRegistro, 'update', '{"diff":{"changes":{"situacao":{"from":"ativo","to":"arquivado"}}}}', '2026-04-01 08:00:00');

        $pelaAuditoria = $this->pasta('arquivado');
        $this->auditoria($pelaAuditoria, 'update', '{"diff":{"changes":{"situacao":{"from":"suspenso","to":"arquivado"}}}}', '2026-06-10 09:30:00');
        $this->auditoria($pelaAuditoria, 'update', '{"diff":{"changes":{"situacao":{"from":"arquivado","to":"ativo"}}}}', '2026-06-11 09:30:00');

        // Arquivada pelo "Editar dados" (sem cartão) depois de um cartão antigo: vale a auditoria.
        $modalDepois = $this->pasta('arquivado');
        $this->cartao($modalDepois, 'Ativo', 'Arquivado', '2026-03-01 10:00:00');
        $this->auditoria($modalDepois, 'update', '{"diff":{"changes":{"situacao":{"from":"ativo","to":"arquivado"}}}}', '2026-07-01 10:00:00');

        $semFonte = $this->pasta('arquivado');
        $this->cartao($semFonte, 'Ativo', 'Suspenso', '2026-05-01 10:00:00');

        $excluida = $this->pasta('arquivado', excluida: true);
        $this->cartao($excluida, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');

        $ativa = $this->pasta('ativo');
        $this->cartao($ativa, 'Ativo', 'Arquivado', '2026-05-01 10:00:00');

        self::assertSame($semFonteAntes + 1, (int) $this->banco->fetchOne(Version20261023150900::SQL_CONTA_SEM_FONTE));

        $this->banco->executeStatement(Version20261023150900::SQL_BACKFILL_ARQUIVADA_EM);

        self::assertSame('2026-05-03 10:00:00', $this->arquivadaEm($peloRegistro));
        self::assertSame('2026-06-10 09:30:00', $this->arquivadaEm($pelaAuditoria));
        self::assertSame('2026-07-01 10:00:00', $this->arquivadaEm($modalDepois));
        self::assertNull($this->arquivadaEm($semFonte));
        self::assertNull($this->arquivadaEm($excluida), 'pasta excluída não conta');
        self::assertNull($this->arquivadaEm($ativa));
    }

    private function pasta(string $situacao, bool $excluida = false): int
    {
        $id = (int) PastaFactory::createOne(['tenant' => $this->tenant])->_real()->getId();
        $this->banco->executeStatement(
            'UPDATE pasta SET situacao = ?, arquivada_em = NULL, excluida_em = ? WHERE id = ?',
            [$situacao, $excluida ? '2026-08-01 00:00:00' : null, $id],
        );

        return $id;
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

    private function auditoria(int $pasta, string $acao, string $mudancas, string $em): void
    {
        $this->banco->executeStatement(
            'INSERT INTO audit_log (action, entity_class, entity_id, changes, tenant_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$acao, Pasta::class, (string) $pasta, $mudancas, $this->tenant->getId(), $em],
        );
    }

    private function arquivadaEm(int $pasta): ?string
    {
        $valor = $this->banco->fetchOne('SELECT arquivada_em FROM pasta WHERE id = ?', [$pasta]);

        return $valor === null ? null : (string) $valor;
    }
}
