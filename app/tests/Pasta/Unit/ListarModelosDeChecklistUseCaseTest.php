<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\UseCase\ListarModelosDeChecklistUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarModelosDeChecklistUseCase::class)]
final class ListarModelosDeChecklistUseCaseTest extends TestCase
{
    #[TestDox('devolve os modelos do escritório com nome, total e títulos dos itens, consultando só o escritório pedido')]
    public function testListaOsModelosDoEscritorio(): void
    {
        $tenant = new Tenant();
        $modelo = (new PastaChecklistModelo())->setTenant($tenant)->setNome('Trabalhista');
        $modelo->adicionarItem((new PastaChecklistModeloItem())->setTitulo('RG')->setOrdem(0));
        $modelo->adicionarItem((new PastaChecklistModeloItem())->setTitulo('CTPS')->setOrdem(1));
        $repositorio = $this->createMock(PastaChecklistModeloRepository::class);
        $repositorio->expects($this->once())->method('listarDoTenant')->with($tenant)->willReturn([$modelo]);

        $saida = (new ListarModelosDeChecklistUseCase($repositorio))->executar($tenant);

        self::assertCount(1, $saida);
        self::assertSame('TRABALHISTA', $saida[0]->nome);
        self::assertSame(2, $saida[0]->totalItens);
        self::assertSame(['RG', 'CTPS'], $saida[0]->itens);
    }
}
