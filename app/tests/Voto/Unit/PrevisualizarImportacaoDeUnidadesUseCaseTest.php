<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\ArquivoDeUnidades;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\UseCase\PrevisualizarImportacaoDeUnidadesUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** A prévia lê e classifica a lista, mostra os erros por linha e não grava nada (o repositório nem é dado ao UseCase). */
#[CoversClass(PrevisualizarImportacaoDeUnidadesUseCase::class)]
#[CoversClass(LeitorDaListaDeUnidades::class)]
final class PrevisualizarImportacaoDeUnidadesUseCaseTest extends TestCase
{
    #[TestDox('Separa as que entrariam, as inválidas (com a linha) e as duplicadas, inclusive as já cadastradas, sem gravar')]
    public function testPrevia(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        new UnidadeDaAssembleia($assembleia, '101', 's', 'h', 'A');

        $previa = $this->caso($tenant, $assembleia)->executar($tenant, 7, "101; A\n102; A; quite; ; Ana; ana@exemplo.com\n; B\n103; A; ; ; ; sem-arroba\n102; A\n");

        self::assertSame(['102'], array_map(static fn ($l): string => $l->identificacao, $previa->validas));
        self::assertSame([['linha' => 3, 'motivo' => 'Linha sem a unidade.'], ['linha' => 4, 'motivo' => 'E-mail inválido.']], $previa->invalidas);
        self::assertSame([1, 5], $previa->duplicadas);
        self::assertCount(1, $assembleia->getUnidades(), 'a prévia não grava unidade nenhuma');
    }

    #[TestDox('O texto da confirmação traz só as válidas e, relido, devolve as mesmas linhas')]
    public function testTextoDaConfirmacaoRelido(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');
        $caso = $this->caso($tenant, $assembleia);

        $previa = $caso->executar($tenant, 7, "101;A;;0,25;\"Ana \"\"Nina\"\"; Souza\";ana@exemplo.com;(11) 99999-0000\n102;A;inadimplente;0,75\n;B;;0,1");
        $relida = (new LeitorDaListaDeUnidades())->ler($previa->texto, PesoDoVoto::Fracao);

        self::assertSame([], $relida['invalidas']);
        self::assertEquals($this->semNumero($previa->validas, $relida['validas']), $relida['validas']);
        self::assertSame(['101', '102'], array_map(static fn ($l): string => $l->identificacao, $relida['validas']));
        self::assertSame('Ana "Nina"; Souza', $relida['validas'][0]->responsavel, 'aspas e ponto e vírgula sobrevivem ao reenvio');
        self::assertSame('0.250000', $relida['validas'][0]->fracao);
        self::assertSame('11999990000', $relida['validas'][0]->telefone);
        self::assertSame('inadimplente', $relida['validas'][1]->situacao);
    }

    #[TestDox('Peso por fração: linha sem fração é inválida na prévia')]
    public function testFracaoObrigatoria(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Fracao, 'Gestora');

        $previa = $this->caso($tenant, $assembleia)->executar($tenant, 7, '101; A');

        self::assertSame([['linha' => 1, 'motivo' => 'Informe a fração ideal.']], $previa->invalidas);
        self::assertSame([], $previa->validas);
    }

    #[TestDox('Assembleia de outro escritório: não encontrada')]
    public function testOutroEscritorio(): void
    {
        $tenant = new Tenant();
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn(null);

        $this->expectException(AssembleiaNaoEncontradaException::class);

        (new PrevisualizarImportacaoDeUnidadesUseCase($assembleias, new LeitorDaListaDeUnidades(), new ArquivoDeUnidades()))->executar($tenant, 7, '101');
    }

    #[TestDox('Depois da abertura da primeira pauta a prévia também é recusada')]
    public function testBloqueada(): void
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

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);

        $this->caso($tenant, $assembleia)->executar($tenant, 7, '102');
    }

    /**
     * @param list<\App\Voto\DTO\LinhaDeUnidade> $a
     * @param list<\App\Voto\DTO\LinhaDeUnidade> $b
     *
     * @return list<\App\Voto\DTO\LinhaDeUnidade>
     */
    private function semNumero(array $a, array $b): array
    {
        return array_map(static fn ($l, $m) => new \App\Voto\DTO\LinhaDeUnidade($m->linha, $l->identificacao, $l->bloco, $l->situacao, $l->fracao, $l->responsavel, $l->email, $l->telefone), $a, $b);
    }

    private function caso(Tenant $tenant, Assembleia $assembleia): PrevisualizarImportacaoDeUnidadesUseCase
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return new PrevisualizarImportacaoDeUnidadesUseCase($assembleias, new LeitorDaListaDeUnidades(), new ArquivoDeUnidades());
    }
}
