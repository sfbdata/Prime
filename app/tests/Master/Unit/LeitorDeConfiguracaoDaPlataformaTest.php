<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\CatalogoDeConfiguracoes;
use App\Master\Service\LeitorDeConfiguracaoDaPlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(LeitorDeConfiguracaoDaPlataforma::class)]
final class LeitorDeConfiguracaoDaPlataformaTest extends TestCase
{
    private ConfiguracaoGlobalRepository&MockObject $repositorio;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(ConfiguracaoGlobalRepository::class);
    }

    private function gravada(array $valores): ConfiguracaoGlobal
    {
        $configuracao = new ConfiguracaoGlobal();
        $configuracao->definirValores($valores, null, new \DateTimeImmutable());

        return $configuracao;
    }

    #[TestDox('O valor gravado vence o padrão')]
    public function testGravadoVenceOPadrao(): void
    {
        $this->repositorio->method('atual')->willReturn($this->gravada(['cadastro' => 'off']));

        self::assertSame('off', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio))->valor('cadastro'));
    }

    #[TestDox('Sem linha gravada, vale o padrão do catálogo')]
    public function testSemLinhaValeOPadrao(): void
    {
        $this->repositorio->method('atual')->willReturn(null);

        $leitor = new LeitorDeConfiguracaoDaPlataforma($this->repositorio);

        self::assertSame(CatalogoDeConfiguracoes::padroes()['cadastro'], $leitor->valor('cadastro'));
        self::assertSame('on', $leitor->valor('cadastro'));
    }

    #[TestDox('Chave ausente na linha gravada cai no padrão da chave')]
    public function testChaveAusenteNaLinhaValeOPadrao(): void
    {
        $this->repositorio->method('atual')->willReturn($this->gravada(['nome' => 'Outro']));

        self::assertSame('on', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio))->valor('cadastro'));
    }

    #[TestDox('Chave fora do catálogo não lança e devolve texto vazio')]
    public function testChaveDesconhecidaNaoLanca(): void
    {
        $this->repositorio->method('atual')->willReturn($this->gravada(['inexistente' => 'x']));

        self::assertSame('', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio))->valor('inexistente'));
    }

    #[TestDox('Exceção do repositório (banco fora, tabela ausente) não lança: vale o padrão')]
    public function testFalhaDoRepositorioValeOPadrao(): void
    {
        $this->repositorio->method('atual')->willThrowException(new \RuntimeException('banco fora'));

        self::assertSame('on', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio))->valor('cadastro'));
    }

    #[TestDox('O padrão de `sessao` vem do ambiente; o gravado vence')]
    public function testPadraoDaSessaoVemDoAmbiente(): void
    {
        $this->repositorio->method('atual')->willReturnOnConsecutiveCalls(null, $this->gravada(['sessao' => '90']));

        self::assertSame('24', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio, null, 24))->valor('sessao'));
        self::assertSame('90', (new LeitorDeConfiguracaoDaPlataforma($this->repositorio, null, 24))->valor('sessao'));
    }
}
