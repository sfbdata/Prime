<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Repository\PastaChecklistItemRepository;
use App\Pasta\UseCase\AplicarChecklistModeloUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(AplicarChecklistModeloUseCase::class)]
final class AplicarChecklistModeloUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaChecklistItemRepository&MockObject $itemRepo;
    private AplicarChecklistModeloUseCase $useCase;
    private Tenant $tenant;
    private Pasta $pasta;

    protected function setUp(): void
    {
        $this->em       = $this->createMock(EntityManagerInterface::class);
        $this->itemRepo = $this->createMock(PastaChecklistItemRepository::class);
        $this->useCase  = new AplicarChecklistModeloUseCase($this->em, $this->itemRepo);

        $this->tenant = new Tenant();
        $this->pasta  = (new Pasta())->setTenant($this->tenant);
    }

    /** @param string[] $titulos */
    private function pastaJaTem(array $titulos): void
    {
        $itens = [];
        foreach ($titulos as $titulo) {
            $itens[] = (new PastaChecklistItem())->setTitulo($titulo)->setTenant($this->tenant);
        }

        $this->itemRepo->method('findByPasta')->willReturn($itens);
    }

    /** @param string[] $titulos */
    private function modeloCom(array $titulos, ?Tenant $tenant = null): PastaChecklistModelo
    {
        $modelo = (new PastaChecklistModelo())->setTenant($tenant ?? $this->tenant)->setNome('Trabalhista');

        $ordem = 1;
        foreach ($titulos as $titulo) {
            $modelo->adicionarItem((new PastaChecklistModeloItem())->setTitulo($titulo)->setOrdem($ordem));
            ++$ordem;
        }

        return $modelo;
    }

    #[TestDox('Aplicar numa pasta vazia cria todos os itens do modelo, pendentes e na ordem')]
    public function testAplicaTodosNaPastaVazia(): void
    {
        $this->pastaJaTem([]);
        $this->itemRepo->method('proximaOrdem')->willReturn(1);

        $criados = [];
        $this->em->expects($this->exactly(3))
            ->method('persist')
            ->willReturnCallback(static function (object $item) use (&$criados): void {
                self::assertInstanceOf(PastaChecklistItem::class, $item);
                $criados[] = $item;
            });
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($this->pasta, $this->modeloCom(['Procuração', 'RG', 'CPF']), $this->tenant);

        self::assertSame(3, $resultado->totalCriados());
        self::assertSame(0, $resultado->totalIgnorados());
        self::assertSame(['PROCURAÇÃO', 'RG', 'CPF'], array_map(
            static fn (PastaChecklistItem $i): string => $i->getTitulo(),
            $criados,
        ));
        self::assertSame([1, 2, 3], array_map(
            static fn (PastaChecklistItem $i): int => $i->getOrdem(),
            $criados,
        ));

        foreach ($criados as $item) {
            self::assertFalse($item->isConcluido(), 'item aplicado nasce pendente');
            self::assertSame($this->pasta, $item->getPasta());
            self::assertSame($this->tenant, $item->getTenant());
        }
    }

    #[TestDox('Item que a pasta já tem é PULADO, não duplicado — e o que já estava marcado fica intacto')]
    public function testPulaOsQueJaExistem(): void
    {
        $jaMarcado = (new PastaChecklistItem())->setTitulo('Procuração')->setTenant($this->tenant);
        $jaMarcado->setConcluido(true);
        $this->itemRepo->method('findByPasta')->willReturn([$jaMarcado]);
        $this->itemRepo->method('proximaOrdem')->willReturn(2);

        $this->em->expects($this->exactly(2))->method('persist');

        $resultado = $this->useCase->executar($this->pasta, $this->modeloCom(['Procuração', 'RG', 'CPF']), $this->tenant);

        self::assertSame(2, $resultado->totalCriados());
        self::assertSame(['PROCURAÇÃO'], $resultado->ignorados);
        self::assertTrue($jaMarcado->isConcluido(), 'o item já conferido não pode ser desmarcado');
        self::assertSame(['RG', 'CPF'], array_column($resultado->criados, 'titulo'));
    }

    #[TestDox('A comparação de repetido ignora a caixa das letras (o domínio grava maiúsculas)')]
    public function testRepetidoEhDetectadoIndependenteDaCaixa(): void
    {
        $this->pastaJaTem(['procuração']);
        $this->itemRepo->method('proximaOrdem')->willReturn(2);

        $this->em->expects($this->never())->method('persist');

        $resultado = $this->useCase->executar($this->pasta, $this->modeloCom(['PROCURAÇÃO']), $this->tenant);

        self::assertSame(0, $resultado->totalCriados());
        self::assertSame(1, $resultado->totalIgnorados());
    }

    #[TestDox('Modelo com dois itens de mesmo título entra uma vez só')]
    public function testModeloComTitulosRepetidosEntraUmaVez(): void
    {
        $this->pastaJaTem([]);
        $this->itemRepo->method('proximaOrdem')->willReturn(1);

        $this->em->expects($this->once())->method('persist');

        $resultado = $this->useCase->executar($this->pasta, $this->modeloCom(['RG', 'RG']), $this->tenant);

        self::assertSame(1, $resultado->totalCriados());
    }

    #[TestDox('Os itens novos continuam a numeração da pasta, sem colidir com os que já estavam lá')]
    public function testOrdemContinuaDeOndeAPastaParou(): void
    {
        $this->pastaJaTem(['Procuração']);
        $this->itemRepo->method('proximaOrdem')->willReturn(7);

        $ordens = [];
        $this->em->method('persist')->willReturnCallback(static function (object $item) use (&$ordens): void {
            self::assertInstanceOf(PastaChecklistItem::class, $item);
            $ordens[] = $item->getOrdem();
        });

        $this->useCase->executar($this->pasta, $this->modeloCom(['RG', 'CPF']), $this->tenant);

        self::assertSame([7, 8], $ordens);
    }

    #[TestDox('Modelo de outro escritório não pode ser aplicado nesta pasta')]
    public function testModeloDeOutroEscritorioEhRecusado(): void
    {
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, $this->modeloCom(['RG'], new Tenant()), $this->tenant);
    }

    #[TestDox('Pasta de outro escritório não recebe modelo do meu')]
    public function testPastaDeOutroEscritorioEhRecusada(): void
    {
        $this->pasta->setTenant(new Tenant());
        $this->em->expects($this->never())->method('persist');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->pasta, $this->modeloCom(['RG']), $this->tenant);
    }
}
