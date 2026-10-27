<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Processo\Entity\Processo;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\UseCase\DefinirDestinoDaPecaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefinirDestinoDaPecaUseCase::class)]
final class DefinirDestinoDaPecaUseCaseTest extends TestCase
{
    private function pastaComVinculo(int $id): array
    {
        $pasta   = new Pasta();
        $vinculo = new PastaProcesso($pasta, new Processo());
        (new \ReflectionProperty(PastaProcesso::class, 'id'))->setValue($vinculo, $id);
        $pasta->getPastaProcessos()->add($vinculo);

        return [$pasta, $vinculo];
    }

    #[TestDox('grava o vínculo da própria pasta como destino da peça, com um flush')]
    public function testGravaDestino(): void
    {
        [$pasta, $vinculo] = $this->pastaComVinculo(7);
        $doc = (new PastaDocumento())->setPasta($pasta);
        $em  = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        (new DefinirDestinoDaPecaUseCase($em))->executar($doc, 7);

        self::assertSame($vinculo, $doc->getProcessoDestino());
    }

    #[TestDox('vínculo vazio volta ao principal (destino nulo)')]
    public function testVazioVoltaAoPrincipal(): void
    {
        [$pasta, $vinculo] = $this->pastaComVinculo(7);
        $doc = (new PastaDocumento())->setPasta($pasta);
        $doc->setProcessoDestino($vinculo);

        (new DefinirDestinoDaPecaUseCase($this->createStub(EntityManagerInterface::class)))->executar($doc, null);

        self::assertNull($doc->getProcessoDestino());
    }

    #[TestDox('vínculo de outra pasta é recusado e nada é gravado')]
    public function testRecusaVinculoDeOutraPasta(): void
    {
        $this->pastaComVinculo(7);
        $doc = (new PastaDocumento())->setPasta(new Pasta());
        $em  = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        (new DefinirDestinoDaPecaUseCase($em))->executar($doc, 7);
    }
}
