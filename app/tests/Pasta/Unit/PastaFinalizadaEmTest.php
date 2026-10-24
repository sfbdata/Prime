<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `finalizadaEm` ("Pastas concluídas" do Dashboard, spec dashboard-parte-1): quem grava é o
 * PastaAndamento::gravar() da via atual da pasta. Chegar à última fase da trilha (com as extras de
 * reativação) grava a data do marco; qualquer outra fase apaga; a linha da outra via não mexe.
 */
#[CoversClass(Pasta::class)]
#[CoversClass(PastaAndamento::class)]
final class PastaFinalizadaEmTest extends TestCase
{
    private const REATIVADA = [['Reativada', 'bi-arrow-repeat'], ['Em andamento', 'bi-hourglass-split'], ['Finalizada', 'bi-flag']];

    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->pasta  = new Pasta();
        $this->pasta->setTenant($this->tenant);
    }

    /**
     * @param array<int, string>                $em
     * @param list<array{0: string, 1: string}> $extra
     */
    private function gravar(PastaAndamento $andamento, int $atual, array $em, array $extra = []): void
    {
        $andamento->gravar($atual, ['em' => $em, 'pul' => [], 'extra' => $extra]);
    }

    #[TestDox('Pasta nova não tem data de finalização; a situação não mexe nela')]
    public function testPastaNovaSemData(): void
    {
        self::assertNull($this->pasta->getFinalizadaEm());

        $this->pasta->setSituacao(Pasta::SITUACAO_ARQUIVADA);

        self::assertNull($this->pasta->getFinalizadaEm(), 'arquivar só conta pelo andamento');
    }

    #[TestDox('Chegar a Finalizada grava a data do marco, no horário local do marco')]
    public function testChegarAFinalizadaGravaOMarco(): void
    {
        $andamento = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_JUDICIAL);

        $this->gravar($andamento, 9, [0 => '2026-10-01T08:00:00-03:00', 9 => '2026-10-10T14:30:00-03:00']);

        self::assertSame('2026-10-10 14:30:00', $this->pasta->getFinalizadaEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Qualquer outra fase apaga a data (voltar de fase)')]
    public function testOutraFaseApaga(): void
    {
        $andamento = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $this->gravar($andamento, 9, [9 => '2026-10-10T14:30:00-03:00']);

        $this->gravar($andamento, 5, [5 => '2026-10-11T09:00:00-03:00']);

        self::assertNull($this->pasta->getFinalizadaEm());
    }

    #[TestDox('Reativada: a Finalizada antiga deixa de ser a última; só a nova Finalizada conta, na data dela')]
    public function testReativarEFinalizarDeNovo(): void
    {
        $andamento = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $em = [9 => '2026-09-01T10:00:00-03:00'];
        $this->gravar($andamento, 9, $em);

        $this->gravar($andamento, 11, $em + [10 => '2026-10-01T10:00:00-03:00', 11 => '2026-10-01T10:00:00-03:00'], self::REATIVADA);
        self::assertNull($this->pasta->getFinalizadaEm(), 'reativar tira da conta');

        $this->gravar($andamento, 12, $em + [12 => '2026-10-20T16:00:00-03:00'], self::REATIVADA);
        self::assertSame('2026-10-20 16:00:00', $this->pasta->getFinalizadaEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('"Reativada" sem o "Em andamento" gravado depois dela conta como o AndamentoDaPasta::fases(): a última é uma a mais')]
    public function testExtraSemEmAndamento(): void
    {
        $andamento = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $extra = [['Reativada', 'bi-arrow-repeat'], ['Finalizada', 'bi-flag']];

        $this->gravar($andamento, 11, [11 => '2026-10-20T16:00:00-03:00'], $extra);
        self::assertNull($this->pasta->getFinalizadaEm(), 'o índice 11 é a Finalizada na lista gravada, mas é o Em andamento na trilha');

        $this->gravar($andamento, 12, [12 => '2026-10-21T16:00:00-03:00'], $extra);
        self::assertSame('2026-10-21 16:00:00', $this->pasta->getFinalizadaEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('A linha da outra via não mexe na data; a da via administrativa vale quando a pasta é administrativa')]
    public function testSoAViaAtual(): void
    {
        $administrativa = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_ADMINISTRATIVA);
        $this->gravar($administrativa, 9, [9 => '2026-10-10T14:30:00-03:00']);
        self::assertNull($this->pasta->getFinalizadaEm(), 'pasta judicial: a trilha administrativa não conta');

        $this->pasta->setAdministrativa(true);
        $this->gravar($administrativa, 9, [9 => '2026-10-10T14:30:00-03:00']);
        self::assertSame('2026-10-10 14:30:00', $this->pasta->getFinalizadaEm()?->format('Y-m-d H:i:s'));

        $judicial = new PastaAndamento($this->tenant, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $this->gravar($judicial, 3, [3 => '2026-10-11T09:00:00-03:00']);
        self::assertNotNull($this->pasta->getFinalizadaEm(), 'pasta administrativa: a trilha judicial não apaga');
    }

    #[TestDox('Regravar a mesma data (até o segundo) não troca o objeto: sem UPDATE nem auditoria à toa')]
    public function testMesmaDataNaoTrocaOObjeto(): void
    {
        $primeira = new \DateTimeImmutable('2026-10-10 14:30:00');
        $this->pasta->setFinalizadaEm($primeira);

        $this->pasta->setFinalizadaEm(new \DateTimeImmutable('2026-10-10 14:30:00'));

        self::assertSame($primeira, $this->pasta->getFinalizadaEm());
    }

    #[TestDox('A lápide e o restaurar da lixeira não mexem na data')]
    public function testLixeiraNaoMexe(): void
    {
        $this->pasta->setFinalizadaEm(new \DateTimeImmutable('2026-10-05'));
        $this->pasta->marcarExcluida((new User())->setEmail('autor@test.com'), new \DateTimeImmutable('2026-10-06'));

        $this->pasta->restaurar();

        self::assertEquals(new \DateTimeImmutable('2026-10-05'), $this->pasta->getFinalizadaEm());
    }
}
