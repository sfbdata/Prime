<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\AssinaturaEletronica\Entity\ModeloDoEscritorio;
use App\AssinaturaEletronica\Exception\ModeloNaoEncontrado;
use App\AssinaturaEletronica\Repository\ModeloDoEscritorioRepository;
use App\AssinaturaEletronica\UseCase\ExcluirModeloUseCase;
use App\AssinaturaEletronica\UseCase\ListarModelosUseCase;
use App\AssinaturaEletronica\UseCase\SalvarModeloUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Criar, salvar, excluir e listar os modelos do escritório: cada operação só alcança o modelo do próprio escritório
 * (o repositório filtra pelo tenant), e o de outro escritório é `ModeloNaoEncontrado` sem gravar nada.
 */
#[CoversClass(SalvarModeloUseCase::class)]
#[CoversClass(ExcluirModeloUseCase::class)]
#[CoversClass(ListarModelosUseCase::class)]
#[CoversClass(ModeloDoEscritorio::class)]
final class UseCasesDoModeloTest extends TestCase
{
    private ModeloDoEscritorioRepository&MockObject $modelos;
    private MockClock $relogio;
    private Tenant $tenant;
    private User $ator;

    protected function setUp(): void
    {
        $this->modelos = $this->createMock(ModeloDoEscritorioRepository::class);
        $this->relogio = new MockClock('2026-11-09 10:00:00');
        $this->tenant = $this->tenant(9);
        $this->ator = new User();
    }

    #[TestDox('criar: o modelo nasce no escritório de quem cria, com o autor, as datas do relógio e o conteúdo conferido')]
    public function testCriar(): void
    {
        $gravado = null;
        $this->modelos->expects(self::never())->method('doEscritorio');
        $this->modelos->expects(self::once())->method('salvar')->willReturnCallback(static function (ModeloDoEscritorio $modelo) use (&$gravado): void {
            (new \ReflectionProperty(ModeloDoEscritorio::class, 'id'))->setValue($modelo, 41);
            $gravado = $modelo;
        });

        $id = $this->salvar()->executar($this->ator, $this->tenant, null, $this->dados(['nome' => 'Procuração', 'folhaBranca' => true]));

        self::assertSame(41, $id);
        self::assertInstanceOf(ModeloDoEscritorio::class, $gravado);
        self::assertSame($this->tenant, $gravado->getTenant());
        self::assertSame($this->ator, $gravado->getCriadoPor());
        self::assertSame('Procuração', $gravado->getNome());
        self::assertTrue($gravado->isFolhaBranca());
        self::assertSame([['papel' => 'Outorgante']], $gravado->getSignatarios());
        self::assertSame('2026-11-09 10:00:00', $gravado->getCriadoEm()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-09 10:00:00', $gravado->getAlteradoEm()->format('Y-m-d H:i:s'));
        self::assertSame('esc-41', $gravado->chave());
    }

    #[TestDox('salvar: troca o conteúdo do modelo do escritório e sobe o alterado_em, sem mudar autor nem criação')]
    public function testSalvar(): void
    {
        $existente = $this->modelo(7, $this->tenant);
        $this->relogio->modify('+2 hours');
        $this->modelos->expects(self::once())->method('doEscritorio')->with(7, $this->tenant)->willReturn($existente);
        $this->modelos->expects(self::once())->method('salvar')->with($existente);

        $id = $this->salvar()->executar(new User(), $this->tenant, 7, $this->dados(['nome' => 'Contrato novo', 'layout' => '2col', 'lateral' => 'CONTRATANTE']));

        self::assertSame(7, $id);
        self::assertSame('Contrato novo', $existente->getNome());
        self::assertSame('2col', $existente->getLayout());
        self::assertSame('CONTRATANTE', $existente->getLateral());
        self::assertSame($this->ator, $existente->getCriadoPor());
        self::assertSame('2026-11-09 10:00:00', $existente->getCriadoEm()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-09 12:00:00', $existente->getAlteradoEm()->format('Y-m-d H:i:s'));
    }

    #[TestDox('salvar: modelo de outro escritório (ou já excluído) é ModeloNaoEncontrado e nada é gravado')]
    public function testSalvarDeOutroEscritorio(): void
    {
        $this->modelos->expects(self::once())->method('doEscritorio')->with(7, $this->tenant)->willReturn(null);
        $this->modelos->expects(self::never())->method('salvar');

        $this->expectException(ModeloNaoEncontrado::class);
        $this->salvar()->executar($this->ator, $this->tenant, 7, $this->dados());
    }

    #[TestDox('excluir: o modelo do escritório sai pelo repositório')]
    public function testExcluir(): void
    {
        $existente = $this->modelo(7, $this->tenant);
        $this->modelos->expects(self::once())->method('doEscritorio')->with(7, $this->tenant)->willReturn($existente);
        $this->modelos->expects(self::once())->method('remover')->with($existente);

        (new ExcluirModeloUseCase($this->modelos))->executar($this->tenant, 7);
    }

    #[TestDox('excluir: modelo de outro escritório é ModeloNaoEncontrado e nada sai')]
    public function testExcluirDeOutroEscritorio(): void
    {
        $this->modelos->expects(self::once())->method('doEscritorio')->with(7, $this->tenant)->willReturn(null);
        $this->modelos->expects(self::never())->method('remover');

        $this->expectException(ModeloNaoEncontrado::class);
        (new ExcluirModeloUseCase($this->modelos))->executar($this->tenant, 7);
    }

    #[TestDox('listar: só os modelos do escritório, na ordem do repositório, no formato Modelo do contrato')]
    public function testListar(): void
    {
        $recente = $this->modelo(8, $this->tenant);
        $antigo = $this->modelo(3, $this->tenant);
        $this->modelos->expects(self::once())->method('doEscritorioEmOrdem')->with($this->tenant)->willReturn([$recente, $antigo]);

        $lista = (new ListarModelosUseCase($this->modelos))->executar($this->tenant);

        self::assertSame([8, 3], array_column($lista['modelos'], 'id'));
        self::assertSame([
            'id' => 8,
            'chave' => 'esc-8',
            'nome' => 'Procuração simples',
            'categoria' => 'Procurações',
            'layout' => '',
            'lateral' => '',
            'folhaBranca' => false,
            'campos' => [['nome', 'Nome completo', 1]],
            'signatarios' => [['papel' => 'Outorgante']],
            'corpo' => [['t' => 'PROCURAÇÃO'], ['p' => 'Eu, [[nome]], nomeio.'], ['a' => 0, 'r' => '[[nome]]']],
            'alteradoEm' => $recente->getAlteradoEm()->format(\DATE_ATOM),
        ], $lista['modelos'][0]);
    }

    private function salvar(): SalvarModeloUseCase
    {
        return new SalvarModeloUseCase($this->modelos, $this->relogio);
    }

    /** @param array<string, mixed> $troca */
    private function dados(array $troca = []): DadosDoModelo
    {
        return DadosDoModelo::deJson(array_merge([
            'nome' => 'Procuração simples',
            'categoria' => 'Procurações',
            'campos' => [['nome', 'Nome completo', 1]],
            'signatarios' => [['papel' => 'Outorgante']],
            'corpo' => [['t' => 'PROCURAÇÃO'], ['p' => 'Eu, [[nome]], nomeio.'], ['a' => 0, 'r' => '[[nome]]']],
        ], $troca));
    }

    private function modelo(int $id, Tenant $tenant): ModeloDoEscritorio
    {
        $modelo = new ModeloDoEscritorio($tenant, $this->ator, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $modelo->aplicar($this->dados(), \DateTimeImmutable::createFromInterface($this->relogio->now()));
        (new \ReflectionProperty(ModeloDoEscritorio::class, 'id'))->setValue($modelo, $id);

        return $modelo;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
