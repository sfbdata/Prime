<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Service\VagaDeZip;
use App\Pasta\Service\VagasDeZipDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * SEC-004b: as vagas de .zip por escritório, com `flock` de verdade num diretório temporário.
 *
 * Duas instâncias do serviço sobre o mesmo diretório fazem o papel de dois workers do FPM: no
 * Linux, a trava de `flock` é do descritor aberto, então dois `fopen` do mesmo arquivo disputam a
 * trava mesmo dentro de um processo só.
 */
#[CoversClass(VagasDeZipDoEscritorio::class)]
#[CoversClass(VagaDeZip::class)]
final class VagasDeZipDoEscritorioTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        // O diretório das travas deriva do project_dir: um falso, único, isola este teste.
        $this->projectDir = '/projeto-falso-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $dir = VagasDeZipDoEscritorio::diretorioPara($this->projectDir, 'test');
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    #[TestDox('duas vagas por escritório: a 3ª tentativa simultânea volta null, sem esperar')]
    public function testTerceiraTentativaNaoEntra(): void
    {
        $tenant = $this->tenant(7);

        $a = $this->worker()->ocupar($tenant);
        $b = $this->worker()->ocupar($tenant);

        self::assertInstanceOf(VagaDeZip::class, $a);
        self::assertInstanceOf(VagaDeZip::class, $b);
        self::assertNull($this->worker()->ocupar($tenant), 'as 2 vagas estão em uso');
    }

    #[TestDox('as vagas são por escritório: o outro não é afetado')]
    public function testOutroEscritorioTemVagasProprias(): void
    {
        $cheio = $this->tenant(7);
        $outro = $this->tenant(8);
        $w1    = $this->worker();
        $w2    = $this->worker();
        $w1->ocupar($cheio);
        $w2->ocupar($cheio);

        self::assertNull($this->worker()->ocupar($cheio));
        self::assertNotNull($this->worker()->ocupar($outro));
    }

    #[TestDox('liberar() devolve a vaga e é idempotente')]
    public function testLiberarDevolveAVaga(): void
    {
        $tenant = $this->tenant(7);
        $a      = $this->worker()->ocupar($tenant);
        $b      = $this->worker()->ocupar($tenant);
        self::assertNotNull($a);
        self::assertNotNull($b);

        $a->liberar();
        $a->liberar();

        self::assertFalse($a->ocupada());
        self::assertNotNull($this->worker()->ocupar($tenant), 'a vaga devolvida pode ser ocupada');
    }

    #[TestDox('kernel.terminate devolve todas as vagas do pedido (o envio do .zip já terminou)')]
    public function testTerminateLiberaTodas(): void
    {
        self::assertSame([KernelEvents::TERMINATE => 'liberarTodas'], VagasDeZipDoEscritorio::getSubscribedEvents());

        $tenant = $this->tenant(7);
        $pedido = $this->worker();
        $a      = $pedido->ocupar($tenant);
        $b      = $this->worker()->ocupar($tenant);
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNull($this->worker()->ocupar($tenant));

        $pedido->liberarTodas();

        self::assertFalse($a->ocupada());
        self::assertTrue($b->ocupada(), 'só as vagas DESTE pedido saem');
        self::assertNotNull($this->worker()->ocupar($tenant));
    }

    #[TestDox('reset() (entre pedidos de um worker que fica vivo) também devolve as vagas')]
    public function testResetLibera(): void
    {
        $tenant = $this->tenant(7);
        $pedido = $this->worker();
        $a      = $pedido->ocupar($tenant);
        self::assertNotNull($a);

        $pedido->reset();

        self::assertFalse($a->ocupada());
    }

    #[TestDox('a vaga que some (objeto destruído) é devolvida — a trava não sobrevive ao descritor')]
    public function testDestruirDevolve(): void
    {
        $tenant = $this->tenant(7);
        $w1     = $this->worker();
        $w2     = $this->worker();
        $w1->ocupar($tenant);
        $w2->ocupar($tenant);
        self::assertNull($this->worker()->ocupar($tenant));

        unset($w1); // o serviço era o único dono da vaga: some com ele

        self::assertNotNull($this->worker()->ocupar($tenant));
    }

    #[TestDox('os arquivos de trava se chamam zip_tenant_<id>_<vaga>.lock, uma por vaga')]
    public function testNomesDosArquivos(): void
    {
        $tenant = $this->tenant(42);
        $w1     = $this->worker();
        $w2     = $this->worker();
        $w1->ocupar($tenant);
        $w2->ocupar($tenant);

        $dir   = VagasDeZipDoEscritorio::diretorioPara($this->projectDir, 'test');
        $nomes = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($nomes);

        self::assertSame(['zip_tenant_42_1.lock', 'zip_tenant_42_2.lock'], $nomes);
    }

    #[TestDox('o diretório separa checkout e ambiente')]
    public function testDiretorioSeparaCheckoutEAmbiente(): void
    {
        self::assertNotSame(
            VagasDeZipDoEscritorio::diretorioPara('/a', 'test'),
            VagasDeZipDoEscritorio::diretorioPara('/b', 'test'),
        );
        self::assertNotSame(
            VagasDeZipDoEscritorio::diretorioPara('/a', 'test'),
            VagasDeZipDoEscritorio::diretorioPara('/a', 'dev'),
        );
    }

    #[TestDox('escritório sem id é erro de programação, não vaga')]
    public function testTenantSemIdLanca(): void
    {
        $this->expectException(\LogicException::class);

        $this->worker()->ocupar(new Tenant());
    }

    // ----------------------------------------------------------------- apoio

    private function worker(): VagasDeZipDoEscritorio
    {
        return new VagasDeZipDoEscritorio($this->projectDir, 'test');
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
