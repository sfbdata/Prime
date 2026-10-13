<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Service\ReaberturaDesfazivelDaMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * PMC-061 — a data de conclusão apagada pelo reabrir fica lembrada NO SERVIDOR (sessão), por meta
 * e por escritório, numa janela curta, e é consumida no primeiro uso.
 */
#[CoversClass(ReaberturaDesfazivelDaMeta::class)]
final class ReaberturaDesfazivelDaMetaTest extends TestCase
{
    private ReaberturaDesfazivelDaMeta $memoria;
    private Session $sessao;

    protected function setUp(): void
    {
        $this->memoria = new ReaberturaDesfazivelDaMeta();
        $this->sessao  = new Session(new MockArraySessionStorage());
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function meta(int $id): Tarefa
    {
        $tarefa = new Tarefa();
        (new \ReflectionProperty(Tarefa::class, 'id'))->setValue($tarefa, $id);

        return $tarefa;
    }

    #[TestDox('guarda e devolve a mesma data (até o microssegundo); o segundo consumo já não acha nada')]
    public function testIdaEVolta(): void
    {
        $agora    = new \DateTimeImmutable('2026-10-07 16:00:00');
        $original = new \DateTimeImmutable('2026-09-10 10:00:00.123456');
        $this->memoria->guardar($this->sessao, $this->meta(7), $this->tenant(1), $original, $agora);

        $devolvida = $this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $agora->modify('+5 seconds'));
        self::assertNotNull($devolvida);
        self::assertSame($original->format('Y-m-d H:i:s.u P'), $devolvida->format('Y-m-d H:i:s.u P'));

        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $agora->modify('+6 seconds')), 'desfazer é uma vez só');
    }

    #[TestDox('outra meta e o mesmo id em OUTRO escritório não pegam a lembrança — que continua valendo para a dona')]
    public function testIsolaPorMetaEEscritorio(): void
    {
        $agora = new \DateTimeImmutable('2026-10-07 16:00:00');
        $this->memoria->guardar($this->sessao, $this->meta(7), $this->tenant(1), new \DateTimeImmutable('2026-09-10 10:00'), $agora);

        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(8), $this->tenant(1), $agora));
        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(2), $agora));
        self::assertNotNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $agora));
    }

    #[TestDox('janela vencida: a lembrança não vale mais')]
    public function testJanelaVencida(): void
    {
        $agora = new \DateTimeImmutable('2026-10-07 16:00:00');
        $this->memoria->guardar($this->sessao, $this->meta(7), $this->tenant(1), new \DateTimeImmutable('2026-09-10 10:00'), $agora);

        $depois = $agora->modify('+' . (ReaberturaDesfazivelDaMeta::JANELA_SEGUNDOS + 1) . ' seconds');
        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $depois));
    }

    #[TestDox('no limite da janela ainda vale')]
    public function testLimiteDaJanela(): void
    {
        $agora = new \DateTimeImmutable('2026-10-07 16:00:00');
        $this->memoria->guardar($this->sessao, $this->meta(7), $this->tenant(1), new \DateTimeImmutable('2026-09-10 10:00'), $agora);

        $limite = $agora->modify('+' . ReaberturaDesfazivelDaMeta::JANELA_SEGUNDOS . ' seconds');
        self::assertNotNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $limite));
    }

    #[TestDox('esquecer apaga só a lembrança daquela meta')]
    public function testEsquecer(): void
    {
        $agora = new \DateTimeImmutable('2026-10-07 16:00:00');
        $this->memoria->guardar($this->sessao, $this->meta(7), $this->tenant(1), new \DateTimeImmutable('2026-09-10 10:00'), $agora);
        $this->memoria->guardar($this->sessao, $this->meta(9), $this->tenant(1), new \DateTimeImmutable('2026-09-11 10:00'), $agora);

        $this->memoria->esquecer($this->sessao, $this->meta(7), $this->tenant(1), $agora);

        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), $agora));
        self::assertSame('2026-09-11', $this->memoria->consumir($this->sessao, $this->meta(9), $this->tenant(1), $agora)?->format('Y-m-d'));
    }

    #[TestDox('lixo na sessão (formato inesperado) é ignorado, sem erro')]
    public function testSessaoCorrompida(): void
    {
        $this->sessao->set('tarefa.reabertura_desfazivel', ['1:7' => ['data' => 'nao-e-data', 'em' => time()], 'x' => 'lixo']);

        self::assertNull($this->memoria->consumir($this->sessao, $this->meta(7), $this->tenant(1), new \DateTimeImmutable()));
    }
}
