<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * A marca da cadeia do selo por documento (spec assinatura-documentos-etapa-8, E8-4), sobre uma loja de travas em
 * memória que guarda o TTL pedido para cada trava (`$ttls`): marca uma vez, a sonda não deixa marca, a marca vale para
 * o documento dela e fica depois de quem marcou (sem soltar no fim), com o TTL fixo de 30 min; só quem marcou a solta.
 */
#[CoversClass(CadeiaDoSeloPendente::class)]
final class CadeiaDoSeloPendenteTest extends TestCase
{
    /** @var array<string, float> o último TTL pedido, por nome de trava */
    private array $ttls = [];

    private CadeiaDoSeloPendente $cadeia;

    protected function setUp(): void
    {
        $guardarTtl = function (Key $chave, float $ttl): void {
            $this->ttls[(string) $chave] = $ttl;
        };
        $loja = new class ($guardarTtl) implements PersistingStoreInterface {
            private readonly InMemoryStore $memoria;

            public function __construct(private readonly \Closure $guardarTtl)
            {
                $this->memoria = new InMemoryStore();
            }

            public function save(Key $key): void
            {
                $this->memoria->save($key);
            }

            public function delete(Key $key): void
            {
                $this->memoria->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->memoria->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                ($this->guardarTtl)($key, $ttl);
                $this->memoria->putOffExpiration($key, $ttl);
            }
        };
        $this->cadeia = new CadeiaDoSeloPendente(new LockFactory($loja));
    }

    #[TestDox('marcar: devolve a marca só na primeira vez (nulo depois); o documento fica pendente')]
    public function testMarcaUmaVez(): void
    {
        self::assertNotNull($this->cadeia->marcar(31));
        self::assertNull($this->cadeia->marcar(31), 'já estava marcada');
        self::assertTrue($this->cadeia->estaPendente(31));
    }

    #[TestDox('quem marcou e não passou o pedido ao worker solta a marca nova: o documento volta a ficar livre')]
    public function testSoltaAMarcaNova(): void
    {
        $marca = $this->cadeia->marcar(31);
        self::assertNotNull($marca);

        $marca->release();

        self::assertFalse($this->cadeia->estaPendente(31));
        self::assertNotNull($this->cadeia->marcar(31));
    }

    #[TestDox('a sonda não deixa marca: sem cadeia, não está pendente, e marcar depois ainda marca')]
    public function testSondaNaoMarca(): void
    {
        self::assertFalse($this->cadeia->estaPendente(31));
        self::assertFalse($this->cadeia->estaPendente(31));
        self::assertNotNull($this->cadeia->marcar(31));
    }

    #[TestDox('a sonda não solta a marca de quem marcou')]
    public function testSondaNaoSoltaAMarca(): void
    {
        $this->cadeia->marcar(31);

        self::assertTrue($this->cadeia->estaPendente(31));
        self::assertTrue($this->cadeia->estaPendente(31));
        self::assertNull($this->cadeia->marcar(31));
    }

    #[TestDox('a marca é por documento')]
    public function testPorDocumento(): void
    {
        $this->cadeia->marcar(31);

        self::assertFalse($this->cadeia->estaPendente(32));
        self::assertNotNull($this->cadeia->marcar(32));
    }

    #[TestDox('a marca fica depois de quem marcou (sem soltar no fim) e expira sozinha em 30 min')]
    public function testTtlFixoSemSoltar(): void
    {
        $this->marcarEmOutroEscopo(31);
        gc_collect_cycles();

        self::assertTrue($this->cadeia->estaPendente(31), 'a trava de quem marcou já saiu de escopo, e a marca ficou');
        self::assertSame(1800.0, $this->ttls['assinatura_pdf_selo_pendente_31'] ?? null);
        self::assertSame(1800.0, CadeiaDoSeloPendente::TTL_EM_SEGUNDOS);
    }

    private function marcarEmOutroEscopo(int $documentoId): void
    {
        self::assertNotNull($this->cadeia->marcar($documentoId));
    }
}
