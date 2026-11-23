<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Tests\Conector\Support\LiberacaoDoConectorDeTeste;
use App\Conector\Service\PortaDoConectorNaLeituraDeDocumentos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Master\Repository\ContasDoSistemaRepository;
use App\Master\Repository\RecursoEstadoRepository;
use App\Master\Service\RecursosDoSistema;
use App\Master\Service\RegraDosMasters;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A porta do conector na leitura de documentos (spec mcp-remoto-oauth, `ler_documentos_e_modelos`, e 14.a): vale a
 * liberação do conector nos dois níveis, não o aceite da BlueJus I.A. Cada nível que falta recusa, sem lançar.
 */
#[CoversClass(PortaDoConectorNaLeituraDeDocumentos::class)]
final class PortaDoConectorNaLeituraDeDocumentosTest extends TestCase
{
    private Tenant $escritorio;
    private ?EscritorioDoConector $doConector;
    /** @var array<string, RecursoEstado> */
    private array $chaves = [];

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setName('Escritório da Porta');
        $this->doConector = new EscritorioDoConector($this->escritorio);
        $this->doConector->ligar(new User(), new \DateTimeImmutable('2026-11-16 09:00:00'));
    }

    #[TestDox('conector liberado, ligado e com o aceite vigente: o aceite cobre')]
    public function testCobre(): void
    {
        self::assertTrue($this->porta()->aceiteCobre($this->escritorio));
    }

    #[TestDox('escritório sem linha do conector: não cobre')]
    public function testSemLinha(): void
    {
        $this->doConector = null;

        self::assertFalse($this->porta()->aceiteCobre($this->escritorio));
    }

    #[TestDox('o administrador do escritório desligou o conector: não cobre')]
    public function testDesligado(): void
    {
        $this->doConector?->desligar();

        self::assertFalse($this->porta()->aceiteCobre($this->escritorio));
    }

    #[TestDox('a chave geral do Master desligada: não cobre')]
    public function testChaveGeralDesligada(): void
    {
        $estado = new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
        $estado->desativar(null, null, null, new \DateTimeImmutable('2026-11-01'));
        $this->chaves[LiberacaoDoConector::CHAVE_GERAL] = $estado;

        self::assertFalse($this->porta()->aceiteCobre($this->escritorio));
    }

    private function porta(): PortaDoConectorNaLeituraDeDocumentos
    {
        $escritorios = $this->createStub(EscritorioDoConectorRepository::class);
        $escritorios->method('buscarDoEscritorio')->willReturnCallback(fn (): ?EscritorioDoConector => $this->doConector);
        $estados = $this->createStub(RecursoEstadoRepository::class);
        $estados->method('todosPorChave')->willReturnCallback(fn (): array => $this->chaves);
        $recursos = new RecursosDoSistema(
            $estados,
            new MockClock('2026-11-16 10:00:00'),
            $this->createStub(Security::class),
            new RegraDosMasters($this->createStub(ContasDoSistemaRepository::class)),
            new RequestStack(),
            new NullLogger(),
        );

        return new PortaDoConectorNaLeituraDeDocumentos(LiberacaoDoConectorDeTeste::montar($recursos, $escritorios));
    }
}
