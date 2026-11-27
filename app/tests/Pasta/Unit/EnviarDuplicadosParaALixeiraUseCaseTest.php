<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\ConsultaDeDuplicadosDaPasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\UseCase\DecisaoSobreGrupoDeDuplicados;
use App\Pasta\UseCase\EnviarDuplicadosParaALixeiraUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A regra de qual cópia fica, a simulação que não grava e o que vira lixeira. Só o ORM e a consulta
 * são dublês; as entidades são reais (a lápide é do `PastaDocumento`).
 */
#[CoversClass(EnviarDuplicadosParaALixeiraUseCase::class)]
#[CoversClass(DecisaoSobreGrupoDeDuplicados::class)]
final class EnviarDuplicadosParaALixeiraUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ConsultaDeDuplicadosDaPasta&MockObject $consulta;
    private PastaDocumentoRepository&MockObject $documentos;
    private EnviarDuplicadosParaALixeiraUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->consulta   = $this->createMock(ConsultaDeDuplicadosDaPasta::class);
        $this->documentos = $this->createMock(PastaDocumentoRepository::class);
        $this->useCase    = new EnviarDuplicadosParaALixeiraUseCase(
            $this->em,
            $this->consulta,
            $this->documentos,
            new MockClock('2026-11-27 10:00:00'),
        );

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
        $this->pasta = (new Pasta())->setTenant($this->tenant);
        (new \ReflectionProperty(Pasta::class, 'id'))->setValue($this->pasta, 50);
    }

    private function doc(int $id): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setPasta($this->pasta);
        $d->setTenant($this->tenant);
        $d->setSha256(str_repeat('a', 64));
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($d, $id);

        return $d;
    }

    /** @param list<int> $ids */
    private function grupo(array $ids): array
    {
        return ['tenant_id' => 7, 'pasta_id' => 50, 'sha256' => str_repeat('a', 64), 'ids' => $ids, 'tamanhos' => array_map(static fn (int $i): int => $i * 10, $ids)];
    }

    /** @param array<int, list<string>> $vinculos */
    private function cenario(array $ids, array $vinculos): void
    {
        $this->consulta->method('grupos')->willReturn([$this->grupo($ids)]);
        $this->consulta->method('vinculos')->willReturn($vinculos);
        $this->consulta->method('gruposDepoisDaPasta')->willReturn(0);
        $docs = array_map(fn (int $i): PastaDocumento => $this->doc($i), $ids);
        $this->documentos->method('findBy')->willReturn($docs);
    }

    #[TestDox('grupo simples sem vínculo: fica a mais antiga, as outras vão para a lixeira como sistema')]
    public function testGrupoSimplesMantemAMaisAntiga(): void
    {
        $this->cenario([1, 2, 3], []);
        $this->em->expects($this->once())->method('flush');

        $r = $this->useCase->executar(true);

        self::assertSame(1, $r->gruposVistos());
        self::assertSame(2, $r->copiasEnviadas());
        self::assertSame(50, $r->bytesALiberar(), '20 + 30');
        self::assertSame(1, $r->pastasTocadas);
        self::assertSame(50, $r->ultimaPasta);
        self::assertSame(1, $r->decisoes[0]->mantidoId);
        self::assertSame([2, 3], $r->decisoes[0]->enviadosIds);
        self::assertSame(DecisaoSobreGrupoDeDuplicados::MAIS_ANTIGA, $r->decisoes[0]->motivo);
    }

    #[TestDox('vínculo na cópia mais nova: ela fica e a antiga vai para a lixeira')]
    public function testVinculoNaCopiaNovaFica(): void
    {
        $this->cenario([1, 2], [2 => ['favorito']]);

        $r = $this->useCase->executar(false);

        self::assertSame(2, $r->decisoes[0]->mantidoId);
        self::assertSame([1], $r->decisoes[0]->enviadosIds);
        self::assertSame(DecisaoSobreGrupoDeDuplicados::COM_VINCULO, $r->decisoes[0]->motivo);
    }

    #[TestDox('duas cópias com vínculo: grupo pulado, nada vai para a lixeira, nem gravando')]
    public function testDoisVinculosPulaOGrupo(): void
    {
        $this->cenario([1, 2, 3], [1 => ['assinatura'], 3 => ['cliente_documento']]);
        $this->em->expects($this->never())->method('flush');

        $r = $this->useCase->executar(true);

        self::assertSame(0, $r->copiasEnviadas());
        self::assertCount(1, $r->pulados());
        self::assertSame(DecisaoSobreGrupoDeDuplicados::VARIOS_VINCULOS, $r->pulados()[0]->motivo);
        self::assertNull($r->pulados()[0]->mantidoId);
        self::assertSame(0, $r->pastasTocadas);
    }

    #[TestDox('DOC e PDF (hash diferente) nunca formam grupo: sem grupo, nada acontece')]
    public function testDocEPdfNaoSaoTocados(): void
    {
        $this->consulta->method('grupos')->willReturn([]);
        $this->documentos->expects($this->never())->method('findBy');
        $this->em->expects($this->never())->method('flush');

        $r = $this->useCase->executar(true);

        self::assertSame(0, $r->gruposVistos());
        self::assertNull($r->ultimaPasta);
        self::assertSame(0, $r->gruposAFrente);
    }

    #[TestDox('simulação decide e relata, mas não marca lixeira, não persiste nem dá flush')]
    public function testSimulacaoNaoGrava(): void
    {
        $this->cenario([1, 2], []);
        $this->em->expects($this->never())->method('flush');
        $this->documentos->expects($this->never())->method('findBy');

        $r = $this->useCase->executar(false);

        self::assertFalse($r->gravou);
        self::assertSame(1, $r->copiasEnviadas());
    }

    #[TestDox('gravando, a cópia enviada fica na lixeira sem autor (sistema) e a mantida segue viva')]
    public function testGravandoMarcaALixeiraSemAutor(): void
    {
        $mantida = $this->doc(1);
        $enviada = $this->doc(2);
        $this->consulta->method('grupos')->willReturn([$this->grupo([1, 2])]);
        $this->consulta->method('vinculos')->willReturn([]);
        $this->consulta->method('gruposDepoisDaPasta')->willReturn(3);
        $this->documentos->method('findBy')->willReturn([$mantida, $enviada]);

        $r = $this->useCase->executar(true);

        self::assertTrue($enviada->estaNaLixeira());
        self::assertNull($enviada->getExcluidoPor());
        self::assertFalse($mantida->estaNaLixeira());
        self::assertSame(3, $r->gruposAFrente);
    }

    #[TestDox('documento de outro escritório/pasta (não volta do findBy escopado): grupo pulado, nada marcado')]
    public function testDocumentoForaDoEscopoRebaixaParaPulado(): void
    {
        $this->consulta->method('grupos')->willReturn([$this->grupo([1, 2])]);
        $this->consulta->method('vinculos')->willReturn([]);
        $this->consulta->method('gruposDepoisDaPasta')->willReturn(0);
        $this->documentos->method('findBy')->willReturn([$this->doc(1)]); // o 2 não veio

        $r = $this->useCase->executar(true);

        self::assertSame(0, $r->copiasEnviadas());
        self::assertSame(DecisaoSobreGrupoDeDuplicados::NAO_ENCONTRADO, $r->decisoes[0]->motivo);
    }

    #[TestDox('limite inválido é recusado')]
    public function testLimiteInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar(false, null, null, 0);
    }
}
