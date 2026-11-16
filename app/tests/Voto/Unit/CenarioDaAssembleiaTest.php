<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\CnpjDaEntidade;
use App\Voto\Service\ConfiguracaoDaPublicacao;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\CriarAssembleiaUseCase;
use App\Voto\UseCase\EditarAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Validation;

/** Cenário (tipo de entidade) da assembleia: rótulos do desenho 18, padrão condomínio, só em rascunho. */
#[CoversClass(CenarioDaAssembleia::class)]
#[CoversClass(Assembleia::class)]
#[CoversClass(AssembleiaInput::class)]
#[CoversClass(CriarAssembleiaUseCase::class)]
#[CoversClass(EditarAssembleiaUseCase::class)]
final class CenarioDaAssembleiaTest extends TestCase
{
    /** @return iterable<string, array{CenarioDaAssembleia, string, string, string}> */
    public static function rotulosDoDesenho(): iterable
    {
        yield 'condomínio' => [CenarioDaAssembleia::Condominio, 'Condomínio', 'Presidência da assembleia', 'Secretaria da assembleia'];
        yield 'classe' => [CenarioDaAssembleia::Classe, 'Entidade', 'Presidência da comissão eleitoral', 'Membro da comissão eleitoral'];
        yield 'associação' => [CenarioDaAssembleia::Associacao, 'Associação', 'Presidência da assembleia', 'Secretaria da assembleia'];
        yield 'sindicato' => [CenarioDaAssembleia::Sindicato, 'Sindicato', 'Presidência da comissão eleitoral', 'Membro da comissão eleitoral'];
        yield 'cooperativa' => [CenarioDaAssembleia::Cooperativa, 'Cooperativa', 'Presidência da assembleia', 'Secretaria da assembleia'];
        yield 'sociedade' => [CenarioDaAssembleia::Sociedade, 'Sociedade', 'Presidência da reunião', 'Secretaria da reunião'];
        yield 'ensino' => [CenarioDaAssembleia::Ensino, 'Instituição', 'Presidência da comissão', 'Membro da comissão'];
        yield 'consulta' => [CenarioDaAssembleia::Consulta, 'Organização', 'Responsável pela consulta', 'Auditoria'];
        yield 'livre' => [CenarioDaAssembleia::Livre, 'Entidade', 'Presidência da comissão', 'Membro da comissão'];
    }

    #[DataProvider('rotulosDoDesenho')]
    #[TestDox('Rótulos do cenário: entidade e as duas assinaturas, como o rot do desenho')]
    public function testRotulosDoDesenho(CenarioDaAssembleia $cenario, string $entidade, string $a1, string $a2): void
    {
        self::assertSame($entidade, $cenario->entidade());
        self::assertSame($a1, $cenario->assinatura1());
        self::assertSame($a2, $cenario->assinatura2());
    }

    #[TestDox('São os 9 cenários do desenho, cada um com rótulo, aviso e ícone, e nenhum texto com travessão')]
    public function testCatalogoCompletoSemTravessao(): void
    {
        self::assertCount(9, CenarioDaAssembleia::cases());
        foreach (CenarioDaAssembleia::cases() as $cenario) {
            foreach ([$cenario->rotulo(), $cenario->ajuda(), $cenario->entidade(), $cenario->assinatura1(), $cenario->assinatura2()] as $texto) {
                self::assertNotSame('', $texto);
                self::assertDoesNotMatchRegularExpression('/[—–]/u', $texto);
            }
            self::assertStringStartsWith('bi-', $cenario->icone());
        }
    }

    #[TestDox('Peso sugerido: fração ideal só no condomínio e nos sócios; os demais, uma unidade um voto')]
    public function testPesoSugerido(): void
    {
        self::assertSame(PesoDoVoto::Fracao, CenarioDaAssembleia::Condominio->pesoSugerido());
        self::assertSame(PesoDoVoto::Fracao, CenarioDaAssembleia::Sociedade->pesoSugerido());
        self::assertSame(PesoDoVoto::Unidade, CenarioDaAssembleia::Cooperativa->pesoSugerido());
        self::assertSame(PesoDoVoto::Unidade, CenarioDaAssembleia::Livre->pesoSugerido());
    }

    #[TestDox('Assembleia e formulário sem cenário ficam como condomínio (comportamento de antes)')]
    public function testPadraoCondominio(): void
    {
        self::assertSame(CenarioDaAssembleia::Condominio, (new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Fracao, 'G'))->getCenario());
        self::assertSame(CenarioDaAssembleia::Condominio, (new AssembleiaInput('AGO', 'fracao'))->cenarioDaAssembleia());
    }

    #[TestDox('Cenário desconhecido no formulário é recusado')]
    public function testCenarioDesconhecidoRecusado(): void
    {
        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new AssembleiaInput('AGO', 'fracao', cenario: 'qualquer'))->cenarioDaAssembleia();
    }

    #[TestDox('Criar grava o cenário escolhido')]
    public function testCriarGravaOCenario(): void
    {
        $salva = null;
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('salvar')->willReturnCallback(static function (Assembleia $a) use (&$salva): void {
            $salva = $a;
        });

        (new CriarAssembleiaUseCase($repo, $this->cnpjs()))->executar(new Tenant(), new AssembleiaInput('AGO', 'unidade', cenario: 'cooperativa'), 'Gestora');

        self::assertInstanceOf(Assembleia::class, $salva);
        self::assertSame(CenarioDaAssembleia::Cooperativa, $salva->getCenario());
    }

    #[TestDox('Editar troca o cenário em rascunho; publicada recusa e mantém o cenário')]
    public function testEditarSoEmRascunho(): void
    {
        $tenant = new Tenant();
        $rascunho = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G');
        $publicada = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G');
        $estado = new \ReflectionProperty(Assembleia::class, 'estado');
        $estado->setValue($publicada, EstadoDaAssembleia::Publicada);

        $this->editar($tenant, $rascunho)->executar($tenant, 1, new AssembleiaInput('AGO', 'unidade', cenario: 'sindicato'));
        self::assertSame(CenarioDaAssembleia::Sindicato, $rascunho->getCenario());

        try {
            $this->editar($tenant, $publicada)->executar($tenant, 1, new AssembleiaInput('AGO', 'unidade', cenario: 'sindicato'));
            self::fail('A publicada não troca o cenário.');
        } catch (OperacaoInvalidaNaAssembleiaException) {
            self::assertSame(CenarioDaAssembleia::Condominio, $publicada->getCenario());
        }
    }

    #[TestDox('O cenário só rotula: o código da configuração publicada é o mesmo em qualquer cenário')]
    public function testCenarioFicaForaDoHashDaConfiguracao(): void
    {
        $tenant = new Tenant();
        $condominio = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G');
        $consulta = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G', CenarioDaAssembleia::Consulta);

        self::assertSame(ConfiguracaoDaPublicacao::codigo($condominio), ConfiguracaoDaPublicacao::codigo($consulta));
    }

    private function cnpjs(): CnpjDaEntidade
    {
        return new CnpjDaEntidade(Validation::createValidator());
    }

    private function editar(Tenant $tenant, Assembleia $assembleia): EditarAssembleiaUseCase
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn($assembleia);

        return new EditarAssembleiaUseCase($repo, new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore())), $this->cnpjs());
    }
}
