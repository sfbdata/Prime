<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Entity\Audit\AuditLog;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O último uso da conexão (spec mcp-remoto-oauth 13.2): no máximo uma gravação por minuto, preso ao escritório, só na
 * conexão ativa, sem `audit_log` e sem levar junto o que estiver pendente na unidade de trabalho.
 */
#[CoversClass(ConsentimentoDoConectorRepository::class)]
final class RegistrarUsoDoConsentimentoTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): ConsentimentoDoConectorRepository
    {
        return static::getContainer()->get(ConsentimentoDoConectorRepository::class);
    }

    #[TestDox('grava na primeira vez; de novo antes de 60 s, não; com 60 s, grava')]
    public function testUmaVezPorMinuto(): void
    {
        $conexao = $this->conexaoAutorizada();
        $consentimento = $conexao['consentimento'];
        $id = (int) $consentimento->getId();
        $escritorio = $conexao['escritorio'];
        $inicio = new \DateTimeImmutable('2026-11-16 10:00:00');

        self::assertTrue($this->sut()->registrarUso($id, $escritorio, $inicio));
        self::assertSame('2026-11-16 10:00:00', $this->ultimoUso($consentimento));

        self::assertFalse($this->sut()->registrarUso($id, $escritorio, $inicio->modify('+59 seconds')));
        self::assertSame('2026-11-16 10:00:00', $this->ultimoUso($consentimento));

        self::assertTrue($this->sut()->registrarUso($id, $escritorio, $inicio->modify('+60 seconds')));
        self::assertSame('2026-11-16 10:01:00', $this->ultimoUso($consentimento));
    }

    #[TestDox('o escritório vai no WHERE: o consentimento com o escritório de outro não é tocado')]
    public function testOutroEscritorioNaoGrava(): void
    {
        $conexao = $this->conexaoAutorizada();
        $outro = $this->escritorio('Outro');

        self::assertFalse($this->sut()->registrarUso((int) $conexao['consentimento']->getId(), $outro, new \DateTimeImmutable()));
        self::assertNull($this->ultimoUso($conexao['consentimento']));
    }

    #[TestDox('conexão revogada não ganha último uso')]
    public function testRevogadaNaoGrava(): void
    {
        $conexao = $this->conexaoAutorizada();
        $conexao['consentimento']->revogar($this->agora());
        $this->em()->flush();

        self::assertFalse($this->sut()->registrarUso((int) $conexao['consentimento']->getId(), $conexao['escritorio'], new \DateTimeImmutable()));
        self::assertNull($this->ultimoUso($conexao['consentimento']));
    }

    #[TestDox('sem audit_log e sem flush: o que estava pendente na unidade de trabalho não vai junto')]
    public function testSemAuditoriaESemFlush(): void
    {
        $conexao = $this->conexaoAutorizada();
        $consentimento = $conexao['consentimento'];
        $antes = $this->registrosDeAuditoria($consentimento);
        $nomeAntes = $conexao['pessoa']->getFullName();
        $conexao['pessoa']->setFullName('Pendente sem flush');

        self::assertTrue($this->sut()->registrarUso((int) $consentimento->getId(), $conexao['escritorio'], new \DateTimeImmutable()));

        self::assertNotNull($this->ultimoUso($consentimento));
        self::assertSame($antes, $this->registrosDeAuditoria($consentimento), 'o último uso não entra no audit_log');
        self::assertSame($nomeAntes, $this->em()->getConnection()->fetchOne('SELECT full_name FROM "user" WHERE id = ?', [$conexao['pessoa']->getId()]), 'nada pendente foi gravado');
        self::assertNull($consentimento->getUltimoUsoEm(), 'a entidade em memória não muda (um flush depois não a audita)');
    }

    private function ultimoUso(ConsentimentoDoConector $consentimento): ?string
    {
        $valor = $this->em()->getConnection()->fetchOne('SELECT ultimo_uso_em FROM conector_ia_consentimento WHERE id = ?', [$consentimento->getId()]);

        return $valor === null || $valor === false ? null : (string) $valor;
    }

    private function registrosDeAuditoria(ConsentimentoDoConector $consentimento): int
    {
        return $this->em()->getRepository(AuditLog::class)->count(['entityClass' => ConsentimentoDoConector::class, 'entityId' => (string) $consentimento->getId()]);
    }
}
