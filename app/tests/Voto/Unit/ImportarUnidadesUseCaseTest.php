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
use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\OrigemDaOcorrencia;
use App\Voto\Repository\OcorrenciaDaUnidadeRepository;
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

        $resultado = (new ImportarUnidadesUseCase(
            $assembleias,
            $unidades,
            new LeitorDaListaDeUnidades(),
            $tokens,
            new ArquivoDeUnidades(),
            self::serializacao(),
            $this->createMock(OcorrenciaDaUnidadeRepository::class),
        ))
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

    #[TestDox('Ocorrência opcional (8ª coluna): entra com origem importação e sem avaliação, mesmo em unidade não apta, e corta em 80')]
    public function testImportaComOcorrencia(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $ocorrencias = [];
        $repositorio = $this->createMock(OcorrenciaDaUnidadeRepository::class);
        $repositorio->method('salvar')->willReturnCallback(static function (OcorrenciaDaUnidade $o) use (&$ocorrencias): void {
            $ocorrencias[(string) $o->getUnidade()->getIdentificacao()] = $o;
        });

        (new ImportarUnidadesUseCase(
            $this->assembleias($tenant, $assembleia),
            $this->createMock(UnidadeDaAssembleiaRepository::class),
            new LeitorDaListaDeUnidades(),
            new TokenDaUnidade('segredo-de-teste'),
            new ArquivoDeUnidades(),
            self::serializacao(),
            $repositorio,
        ))
            ->executar($tenant, 7, "101;A;;;;;;Cadastro desatualizado\n102;A;inadimplente;;;;;" . str_repeat('x', 100) . "\n103;A\n");

        self::assertSame(['101', '102'], array_map('strval', array_keys($ocorrencias)), 'sem a coluna, nenhuma ocorrência');
        self::assertSame('Cadastro desatualizado', $ocorrencias['101']->getTexto());
        self::assertSame(OrigemDaOcorrencia::Importacao, $ocorrencias['101']->getOrigem());
        self::assertSame('Importação da lista', $ocorrencias['101']->getRegistradaPorNome());
        self::assertNull($ocorrencias['101']->getAvaliacao());
        self::assertNull($ocorrencias['102']->getAvaliacao(), 'a importação nunca abre avaliação, nem em unidade não apta');
        self::assertSame(80, mb_strlen($ocorrencias['102']->getTexto()));
    }

    #[TestDox('Telefone opcional (7ª coluna): fica só com os dígitos; vazio ou sem dígito vira nulo')]
    public function testImportaComTelefone(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $salvas = [];
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('salvar')->willReturnCallback(static function (UnidadeDaAssembleia $u) use (&$salvas): void {
            $salvas[$u->getIdentificacao()] = $u;
        });

        (new ImportarUnidadesUseCase(
            $this->assembleias($tenant, $assembleia),
            $unidades,
            new LeitorDaListaDeUnidades(),
            new TokenDaUnidade('segredo-de-teste'),
            new ArquivoDeUnidades(),
            self::serializacao(),
            $this->createMock(OcorrenciaDaUnidadeRepository::class),
        ))
            ->executar($tenant, 7, "Unidade;Bloco;Situação;Fração;Responsável;E-mail;Telefone\n101;A;;;Ana;;(61) 99999-0001\n102;A;;;;;\n103;A;;;;;sem telefone\n104;A\n");

        self::assertSame('61999990001', $salvas['101']->getTelefone());
        self::assertNull($salvas['102']->getTelefone());
        self::assertNull($salvas['103']->getTelefone());
        self::assertNull($salvas['104']->getTelefone());
    }

    #[TestDox('O modelo para baixar traz a coluna Telefone depois de E-mail')]
    public function testModeloTrazTelefone(): void
    {
        ob_start();
        (new ArquivoDeUnidades())->modelo()->sendContent();
        $bytes = (string) ob_get_clean();
        $caminho = tempnam(sys_get_temp_dir(), 'modelo');
        file_put_contents($caminho, $bytes);
        try {
            $linha = \PhpOffice\PhpSpreadsheet\IOFactory::load($caminho)->getSheet(0)->toArray()[0];
        } finally {
            unlink($caminho);
        }

        self::assertSame(['Unidade', 'Bloco', 'Situação', 'Fração', 'Responsável', 'E-mail', 'Telefone', 'Ocorrência'], $linha);
    }

    #[TestDox('O modelo do cenário abre com o rótulo da identificação e do grupo do cenário')]
    public function testModeloComRotulosDoCenario(): void
    {
        ob_start();
        (new ArquivoDeUnidades())->modelo(\App\Voto\Enum\CenarioDaAssembleia::Cooperativa)->sendContent();
        $bytes = (string) ob_get_clean();
        $caminho = tempnam(sys_get_temp_dir(), 'modelo');
        file_put_contents($caminho, $bytes);
        try {
            $linha = \PhpOffice\PhpSpreadsheet\IOFactory::load($caminho)->getSheet(0)->toArray()[0];
        } finally {
            unlink($caminho);
        }

        self::assertSame(['Matrícula de cooperado', 'Núcleo'], \array_slice($linha, 0, 2));
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

        (new ImportarUnidadesUseCase(
            $this->assembleias($tenant, $assembleia),
            $unidades,
            new LeitorDaListaDeUnidades(),
            new TokenDaUnidade('segredo'),
            new ArquivoDeUnidades(),
            self::serializacao(),
            $this->createMock(OcorrenciaDaUnidadeRepository::class),
        ))
            ->executar($tenant, 7, '102; A');
    }

    private function assembleias(Tenant $tenant, Assembleia $assembleia): AssembleiaRepository&MockObject
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);

        return $assembleias;
    }
    private static function serializacao(): \App\Voto\Service\SerializacaoDaConfiguracao
    {
        return new \App\Voto\Service\SerializacaoDaConfiguracao(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore()));
    }

}
