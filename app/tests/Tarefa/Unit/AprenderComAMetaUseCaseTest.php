<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Profile\Preferencia\CatalogoDePreferenciasDoPerfil;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use App\Tarefa\Service\PreferenciaDoAprendizado;
use App\Tarefa\UseCase\AprenderComAMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * O que o aprendizado grava (spec `minhas-metas-esteira-2210.md`, Fase 4; desenho 04B de 22/10, L145 e L173).
 */
#[CoversClass(AprenderComAMetaUseCase::class)]
#[CoversClass(PreferenciaDoAprendizado::class)]
#[Group('tarefa')]
final class AprenderComAMetaUseCaseTest extends TestCase
{
    /** Segunda-feira, longe de feriado em Alagoas. */
    private const HOJE = '2026-03-02';

    private Tenant $escritorio;
    private User $autor;
    private Tarefa $meta;
    /** @var AprendizadoDoDespachoRepository&MockObject */
    private AprendizadoDoDespachoRepository $despachos;
    /** @var AprendizadoDaPendenciaRepository&MockObject */
    private AprendizadoDaPendenciaRepository $pendencias;
    /** @var list<object> */
    private array $salvos = [];

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setEstado('AL');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->escritorio, 3);
        $this->autor = new User();
        $this->meta = (new Tarefa())->setTitulo('Meta')->setTenant($this->escritorio)->setEtapa(EtapaDaPasta::Juridico);
        $this->despachos = $this->createMock(AprendizadoDoDespachoRepository::class);
        $this->pendencias = $this->createMock(AprendizadoDaPendenciaRepository::class);
        $guardar = function (object $registro): void {
            $this->salvos[] = $registro;
        };
        $this->despachos->method('salvar')->willReturnCallback($guardar);
        $this->pendencias->method('salvar')->willReturnCallback($guardar);
    }

    #[TestDox('Despachar com texto grava etapa, natureza, texto e os dias úteis do prazo pela regra do escritório')]
    public function testGravaODespacho(): void
    {
        $gravou = $this->useCase()->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('  Junte a procuração.  ', '', 'providencia', '2026-03-09'), new \DateTimeImmutable(self::HOJE));

        self::assertTrue($gravou);
        self::assertCount(1, $this->salvos);
        $registro = $this->salvos[0];
        self::assertInstanceOf(AprendizadoDoDespacho::class, $registro);
        self::assertSame(EtapaDaPasta::Juridico, $registro->getEtapa());
        self::assertSame(NaturezaDoDespacho::Providencia, $registro->getNatureza());
        self::assertSame('Junte a procuração.', $registro->getTexto());
        self::assertSame(5, $registro->getDiasUteis(), 'de segunda a segunda: 5 dias úteis');
        self::assertSame($this->autor, $registro->getUsuario());
        self::assertSame($this->escritorio, $registro->getTenant());
    }

    #[TestDox('Sem prazo, 0 dias úteis; as @menções viram "@Nome", sem o id da pessoa')]
    public function testSemPrazoEMencao(): void
    {
        $this->useCase()->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('Fale com @[Ana Lima](user:42) hoje.'), new \DateTimeImmutable(self::HOJE));

        self::assertInstanceOf(AprendizadoDoDespacho::class, $this->salvos[0]);
        self::assertSame('Fale com @Ana Lima hoje.', $this->salvos[0]->getTexto());
        self::assertSame(0, $this->salvos[0]->getDiasUteis());
    }

    #[TestDox('Não grava: envio para revisão, despacho sem texto (só anexo), chave desligada no Meu Perfil e meta de outro escritório')]
    public function testQuandoNaoGrava(): void
    {
        $hoje = new \DateTimeImmutable(self::HOJE);
        self::assertFalse($this->useCase()->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('Revise.', 'enviar'), $hoje));
        self::assertFalse($this->useCase()->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('   '), $hoje));
        self::assertFalse($this->useCase(false)->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('Junte.'), $hoje));
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 99);
        self::assertFalse($this->useCase()->doDespacho($this->meta, $this->autor, $outro, $this->input('Junte.'), $hoje));
        self::assertSame([], $this->salvos);
    }

    #[TestDox('Guarda só os 400 despachos mais recentes da pessoa: os de depois do 400º saem')]
    public function testPodaOsAntigos(): void
    {
        $antigo = new AprendizadoDoDespacho($this->escritorio, $this->autor, EtapaDaPasta::Juridico, null, 'Antigo', 0);
        $this->despachos->expects(self::once())->method('doUsuario')
            ->with($this->escritorio, $this->autor, null, AprenderComAMetaUseCase::LIMITE_DE_DESPACHOS)
            ->willReturn([$antigo]);
        $this->despachos->expects(self::once())->method('remover')->with($antigo);

        $this->useCase()->doDespacho($this->meta, $this->autor, $this->escritorio, $this->input('Novo.'), new \DateTimeImmutable(self::HOJE));
    }

    #[TestDox('Pendências: cada linha não vazia, aparada, pela etapa destino; poda acima de 300')]
    public function testGravaAsPendencias(): void
    {
        $this->pendencias->expects(self::once())->method('doUsuario')
            ->with($this->escritorio, $this->autor, null, AprenderComAMetaUseCase::LIMITE_DE_PENDENCIAS)
            ->willReturn([]);

        $linhas = $this->useCase()->dasPendencias($this->autor, $this->escritorio, EtapaDaPasta::Documentacao, " Procuração \r\n\r\nRG\n");

        self::assertSame(2, $linhas);
        self::assertSame(['Procuração', 'RG'], array_map(static fn (object $p): string => $p instanceof AprendizadoDaPendencia ? $p->getLinha() : '', $this->salvos));
        self::assertInstanceOf(AprendizadoDaPendencia::class, $this->salvos[0]);
        self::assertSame(EtapaDaPasta::Documentacao, $this->salvos[0]->getEtapaDestino());
    }

    #[TestDox('Pendências: sem etapa destino (a pasta encerrou), sem linhas ou com a chave desligada, nada é gravado')]
    public function testPendenciasQuandoNaoGrava(): void
    {
        self::assertSame(0, $this->useCase()->dasPendencias($this->autor, $this->escritorio, null, 'RG'));
        self::assertSame(0, $this->useCase()->dasPendencias($this->autor, $this->escritorio, EtapaDaPasta::Juridico, " \n "));
        self::assertSame(0, $this->useCase(false)->dasPendencias($this->autor, $this->escritorio, EtapaDaPasta::Juridico, 'RG'));
        self::assertSame([], $this->salvos);
    }

    private function input(string $texto, string $acao = '', ?string $natureza = null, ?string $prazo = null): DespacharNaMetaInput
    {
        return new DespacharNaMetaInput($texto, $acao, $natureza, $prazo, new \DateTimeImmutable(self::HOJE));
    }

    private function useCase(bool $ligado = true): AprenderComAMetaUseCase
    {
        $preferencias = $this->createStub(PreferenciaDoUsuarioRepository::class);
        $preferencias->method('valoresDoUsuario')->willReturn($ligado ? [] : [CatalogoDePreferenciasDoPerfil::SUGESTOES_DESPACHOS => false]);

        return new AprenderComAMetaUseCase(
            $this->despachos,
            $this->pendencias,
            new PreferenciaDoAprendizado($preferencias),
            new DiasUteisDoEscritorio(new CalendarioDoSistema()),
        );
    }
}
