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
use App\Tarefa\DTO\SugestoesDaMetaOutput;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use App\Tarefa\Entity\AprendizadoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Repository\AprendizadoDaPendenciaRepository;
use App\Tarefa\Repository\AprendizadoDoDespachoRepository;
use App\Tarefa\Service\PreferenciaDoAprendizado;
use App\Tarefa\UseCase\MontarSugestoesDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As sugestões que a meta mostra (desenho 04B de 22/10, L61, L99 e L151-158; Fase 4): só da própria pessoa no
 * escritório da sessão, nada com a chave do Meu Perfil desligada.
 */
#[CoversClass(MontarSugestoesDaMetaUseCase::class)]
#[CoversClass(SugestoesDaMetaOutput::class)]
#[Group('tarefa')]
final class MontarSugestoesDaMetaUseCaseTest extends TestCase
{
    private const HOJE = '2026-03-02';

    private Tenant $escritorio;
    private User $pessoa;
    private Tarefa $meta;

    protected function setUp(): void
    {
        $this->escritorio = (new Tenant())->setEstado('AL');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->escritorio, 3);
        $this->pessoa = new User();
        $this->meta = (new Tarefa())->setTitulo('Meta')->setTenant($this->escritorio)->setEtapa(EtapaDaPasta::Juridico);
    }

    #[TestDox('Lê só o que é da pessoa no escritório; frases por natureza, a preferida já marcada e o prazo usual em data')]
    public function testSugestoesDoDespacho(): void
    {
        $despachos = $this->createMock(AprendizadoDoDespachoRepository::class);
        $despachos->expects(self::once())->method('doUsuario')->with($this->escritorio, $this->pessoa, 400)->willReturn([
            $this->despacho('Providencie o RG.', NaturezaDoDespacho::Providencia, 3),
            $this->despacho('Providencie o RG.', NaturezaDoDespacho::Providencia, 3),
            $this->despacho('Ciente.', NaturezaDoDespacho::Ciencia, 7),
        ]);

        $saida = $this->useCase($despachos)->doDespacho($this->meta, $this->pessoa, $this->escritorio, new \DateTimeImmutable(self::HOJE));

        self::assertSame('providencia', $saida->natureza);
        self::assertSame(['Providencie o RG.', 'Ciente.'], array_column($saida->frases[''], 'texto'));
        self::assertSame(['Ciente.'], array_column($saida->frases['ciencia'], 'texto'));
        self::assertSame([], $saida->frases['cumpra_se']);
        self::assertSame(['Providencie o RG.'], array_column($saida->frasesIniciais(), 'texto'), 'a lista da natureza já marcada');
        self::assertSame(3, $saida->prazoUsual);
        self::assertSame('2026-03-05', $saida->prazo, '3 dias úteis depois de segunda');
        self::assertTrue($saida->naEtapa);
        self::assertTrue($saida->temFrases());
    }

    #[TestDox('Prazo usual igual ao padrão (5): não troca a data; sem natureza nem prazo na etapa, a dica é a geral')]
    public function testPrazoPadraoEDicaGeral(): void
    {
        $despachos = $this->createStub(AprendizadoDoDespachoRepository::class);
        $despachos->method('doUsuario')->willReturn([$this->despacho('a', null, 5), $this->despacho('b', null, 5)]);
        $saida = $this->useCase($despachos)->doDespacho($this->meta, $this->pessoa, $this->escritorio, new \DateTimeImmutable(self::HOJE));
        self::assertSame(5, $saida->prazoUsual);
        self::assertNull($saida->prazo);

        $outraEtapa = $this->createStub(AprendizadoDoDespachoRepository::class);
        $outraEtapa->method('doUsuario')->willReturn([new AprendizadoDoDespacho($this->escritorio, $this->pessoa, EtapaDaPasta::Protocolo, null, 'Protocole.', 0)]);
        $geral = $this->useCase($outraEtapa)->doDespacho($this->meta, $this->pessoa, $this->escritorio, new \DateTimeImmutable(self::HOJE));
        self::assertFalse($geral->naEtapa);
        self::assertSame(['Protocole.'], array_column($geral->frases[''], 'texto'));
    }

    #[TestDox('Chave desligada, meta de outro escritório ou nada aprendido: nenhuma sugestão (desligada nem consulta)')]
    public function testNenhuma(): void
    {
        $despachos = $this->createMock(AprendizadoDoDespachoRepository::class);
        $despachos->expects(self::never())->method('doUsuario');
        $hoje = new \DateTimeImmutable(self::HOJE);
        self::assertFalse($this->useCase($despachos, false)->doDespacho($this->meta, $this->pessoa, $this->escritorio, $hoje)->temFrases());
        $outro = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 99);
        self::assertNull($this->useCase($despachos)->doDespacho($this->meta, $this->pessoa, $outro, $hoje)->natureza);

        $vazio = $this->createStub(AprendizadoDoDespachoRepository::class);
        $vazio->method('doUsuario')->willReturn([]);
        self::assertEquals(SugestoesDaMetaOutput::nenhuma(), $this->useCase($vazio)->doDespacho($this->meta, $this->pessoa, $this->escritorio, $hoje));
    }

    #[TestDox('Você costuma pedir: as linhas da pessoa por etapa destino; desligada, nada')]
    public function testPedidos(): void
    {
        $pendencias = $this->createMock(AprendizadoDaPendenciaRepository::class);
        $pendencias->expects(self::once())->method('doUsuario')->with($this->escritorio, $this->pessoa, 300)->willReturn([
            new AprendizadoDaPendencia($this->escritorio, $this->pessoa, EtapaDaPasta::Documentacao, 'RG'),
            new AprendizadoDaPendencia($this->escritorio, $this->pessoa, EtapaDaPasta::Protocolo, 'Guia paga'),
        ]);
        $despachos = $this->createStub(AprendizadoDoDespachoRepository::class);

        self::assertSame(['documentacao' => ['RG'], 'protocolo' => ['Guia paga']], $this->useCase($despachos, true, $pendencias)->pedidos($this->pessoa, $this->escritorio));
        self::assertSame([], $this->useCase($despachos, false, $pendencias)->pedidos($this->pessoa, $this->escritorio));
    }

    private function despacho(string $texto, ?NaturezaDoDespacho $natureza, int $dias): AprendizadoDoDespacho
    {
        return new AprendizadoDoDespacho($this->escritorio, $this->pessoa, EtapaDaPasta::Juridico, $natureza, $texto, $dias);
    }

    private function useCase(AprendizadoDoDespachoRepository $despachos, bool $ligado = true, ?AprendizadoDaPendenciaRepository $pendencias = null): MontarSugestoesDaMetaUseCase
    {
        $preferencias = $this->createStub(PreferenciaDoUsuarioRepository::class);
        $preferencias->method('valoresDoUsuario')->willReturn($ligado ? [] : [CatalogoDePreferenciasDoPerfil::SUGESTOES_DESPACHOS => false]);

        return new MontarSugestoesDaMetaUseCase(
            $despachos,
            $pendencias ?? $this->createStub(AprendizadoDaPendenciaRepository::class),
            new PreferenciaDoAprendizado($preferencias),
            new DiasUteisDoEscritorio(new CalendarioDoSistema()),
        );
    }
}
