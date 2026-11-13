<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDaImportacao;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\TokenDaUnidade;
use App\Voto\UseCase\ImportarUnidadesUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Importação da lista de unidades (spec voto-assembleia-condominio, Gestor item 4): só acrescenta (quem já está na
 * lista conta como duplicada, para os links enviados continuarem valendo), relata inválidas e duplicadas, guarda só o
 * hash do token e fica bloqueada depois da abertura da primeira pauta.
 */
#[CoversClass(ImportarUnidadesUseCase::class)]
#[CoversClass(ResultadoDaImportacao::class)]
final class ImportarUnidadesUseCaseTest extends TestCase
{
    #[TestDox('Acrescenta as válidas; a que já estava na lista e a repetida no texto são duplicadas; a sem unidade é inválida')]
    public function testImporta(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        new UnidadeDaAssembleia($assembleia, '101', 'semente-antiga', 'hash-antigo', 'A');
        $tokens = new TokenDaUnidade('segredo-de-teste');
        $salvas = [];
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->expects(self::exactly(2))->method('salvar')->willReturnCallback(static function (UnidadeDaAssembleia $u) use (&$salvas): void {
            $salvas[] = $u;
        });
        $assembleias = $this->assembleias($tenant, $assembleia);
        $assembleias->expects(self::once())->method('salvar')->with($assembleia, true);

        $resultado = (new ImportarUnidadesUseCase($assembleias, $unidades, new LeitorDaListaDeUnidades(), $tokens, new ArquivoDeUnidades()))
            ->executar($tenant, 7, "101; A\n102; A; quite; ; Ana; ana@exemplo.com\n103; A; inadimplente\n; B\n102; A\n");

        self::assertSame(2, $resultado->importadas);
        self::assertSame([['linha' => 4, 'motivo' => 'Linha sem a unidade.']], $resultado->invalidas);
        self::assertSame([1, 5], $resultado->duplicadas);
        self::assertSame(5, $resultado->total());
        self::assertSame(['102', '103'], array_map(static fn (UnidadeDaAssembleia $u): string => $u->getIdentificacao(), $salvas));
        self::assertTrue($salvas[0]->isApta());
        self::assertFalse($salvas[1]->isApta(), 'inadimplente fica na lista como não apta');
        self::assertSame('ana@exemplo.com', $salvas[0]->getEmail());
        self::assertSame($tokens->hash($tokens->token($salvas[0]->getSemente())), $salvas[0]->getTokenHash());
        self::assertNotSame($salvas[0]->getSemente(), $salvas[1]->getSemente());
    }

    #[TestDox('Depois da abertura da primeira pauta a lista fica bloqueada')]
    public function testBloqueadaDepoisDaAbertura(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        new UnidadeDaAssembleia($assembleia, '101', 'semente', 'hash');
        $assembleia->publicar();
        $pauta->abrir('Mesa', 1, null);
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->expectExceptionMessage('A lista fica bloqueada depois da abertura da votação.');

        (new ImportarUnidadesUseCase($this->assembleias($tenant, $assembleia), $unidades, new LeitorDaListaDeUnidades(), new TokenDaUnidade('segredo'), new ArquivoDeUnidades()))
            ->executar($tenant, 7, '102; A');
    }

    private function assembleias(Tenant $tenant, Assembleia $assembleia): AssembleiaRepository&MockObject
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return $assembleias;
    }
}
