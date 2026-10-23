<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\DataComemorativaOutput;
use App\Comercial\Entity\DataComemorativa;
use App\Comercial\Exception\DataComemorativaRecusada;
use App\Comercial\Repository\DataComemorativaRepository;
use App\Comercial\UseCase\AlterarDataComemorativaUseCase;
use App\Comercial\UseCase\ListarDatasComemorativasUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** O bloco "Datas comemorativas" (desenho 05 VS 21.10.26 L335-339 e L678-682; spec comercial-e-emails.md §13, F4). */
#[CoversClass(ListarDatasComemorativasUseCase::class)]
#[CoversClass(AlterarDataComemorativaUseCase::class)]
#[CoversClass(DataComemorativaOutput::class)]
#[CoversClass(DataComemorativa::class)]
final class DatasComemorativasUseCasesTest extends TestCase
{
    private DataComemorativaRepository&MockObject $repositorio;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(DataComemorativaRepository::class);
    }

    #[TestDox('As 17 na ordem da próxima data a partir de hoje, com a data, o dia da semana, os dias que faltam, o envio e o "perto"')]
    public function testListarEmOrdem(): void
    {
        $this->repositorio->method('estados')->willReturn([
            'finados' => ['ligada' => true, 'antecedencia' => 1, 'publico' => 'prim'],
            'natal' => ['ligada' => false, 'antecedencia' => 0, 'publico' => 'todos'],
        ]);
        $saida = (new ListarDatasComemorativasUseCase($this->repositorio, new MockClock('2026-10-28 08:00', 'America/Sao_Paulo')))->executar();

        self::assertSame(
            ['finados', 'consciencia', 'natal', 'reveillon', 'ano', 'carnaval', 'mulher', 'pascoa', 'tiradentes', 'trabalho', 'maes', 'namorados', 'pais', 'advogado', 'independencia', 'cliente', 'criancas'],
            array_map(static fn (DataComemorativaOutput $d): string => $d->chave, $saida->datas),
        );
        self::assertSame([
            'chave' => 'finados', 'nome' => 'Finados', 'icone' => 'bi-flower3', 'ligada' => true, 'antecedencia' => 1, 'publico' => 'prim',
            'data' => '2026-11-02', 'diaDaSemana' => 1, 'diasQueFaltam' => 5, 'envio' => '2026-11-01', 'perto' => true,
        ], $saida->datas[0]->paraArray());
        self::assertFalse($saida->datas[1]->perto, 'Consciência Negra a 23 dias');
        self::assertSame(['ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'], ['ligada' => $saida->datas[3]->ligada, 'antecedencia' => $saida->datas[3]->antecedencia, 'publico' => $saida->datas[3]->publico], 'sem linha: o padrão do dc');
        self::assertSame(16, $saida->ativas, 'o Natal desligado não conta');
    }

    #[TestDox('No dia da data ela vem primeiro, com 0 dias; o Ano Novo seguinte entra com o ano novo')]
    public function testListarNoDia(): void
    {
        $this->repositorio->method('estados')->willReturn([]);
        $saida = (new ListarDatasComemorativasUseCase($this->repositorio, new MockClock('2026-12-25 22:00', 'America/Sao_Paulo')))->executar();

        self::assertSame(['natal', 0, '2026-12-25'], [$saida->datas[0]->chave, $saida->datas[0]->diasQueFaltam, $saida->datas[0]->data]);
        self::assertSame(['reveillon', 'ano'], [$saida->datas[1]->chave, $saida->datas[2]->chave]);
        self::assertSame('2027-01-01', $saida->datas[2]->data);
        self::assertSame(17, $saida->ativas);
    }

    #[TestDox('Alterar grava ligada, antecedência, público, quem e quando, e devolve a linha nova')]
    public function testAlterar(): void
    {
        $natal = new DataComemorativa('natal');
        $this->repositorio->method('porChave')->with('natal')->willReturn($natal);
        $this->repositorio->expects(self::once())->method('salvar');

        $linha = $this->alterar()->executar('natal', false, 3, 'leads', new User());

        self::assertSame([false, 3, 'leads'], [$natal->isLigada(), $natal->getAntecedencia(), $natal->getPublico()]);
        self::assertSame(['natal', false, 3, 'leads', '2026-12-25', '2026-12-22'], [$linha->chave, $linha->ligada, $linha->antecedencia, $linha->publico, $linha->data, $linha->envio]);
        $quem = (new \ReflectionProperty(DataComemorativa::class, 'alteradaPor'))->getValue($natal);
        $quando = (new \ReflectionProperty(DataComemorativa::class, 'alteradaEm'))->getValue($natal);
        self::assertInstanceOf(User::class, $quem);
        self::assertSame('2026-10-21 10:00', $quando instanceof \DateTimeImmutable ? $quando->format('Y-m-d H:i') : null);
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function recusas(): iterable
    {
        yield 'chave desconhecida' => ['carnavalx', 0, 'todos', AlterarDataComemorativaUseCase::DESCONHECIDA];
        yield 'antecedência 2' => ['natal', 2, 'todos', AlterarDataComemorativaUseCase::ANTECEDENCIA_INVALIDA];
        yield 'antecedência 7' => ['natal', 7, 'todos', AlterarDataComemorativaUseCase::ANTECEDENCIA_INVALIDA];
        yield 'público sec' => ['natal', 0, 'sec', AlterarDataComemorativaUseCase::PUBLICO_INVALIDO];
        yield 'público csv' => ['natal', 0, 'csv', AlterarDataComemorativaUseCase::PUBLICO_INVALIDO];
    }

    #[TestDox('Recusa $_dataName sem gravar')]
    #[DataProvider('recusas')]
    public function testRecusas(string $chave, int $antecedencia, string $publico, string $mensagem): void
    {
        $this->repositorio->method('porChave')->willReturn(new DataComemorativa($chave));
        $this->repositorio->expects(self::never())->method('salvar');
        $this->expectException(DataComemorativaRecusada::class);
        $this->expectExceptionMessage($mensagem);

        $this->alterar()->executar($chave, true, $antecedencia, $publico, new User());
    }

    #[TestDox('Uma das 17 sem linha no banco ganha a linha na alteração, partindo do padrão')]
    public function testSemLinhaCriaALinha(): void
    {
        $this->repositorio->method('porChave')->willReturn(null);
        $criada = null;
        $this->repositorio->expects(self::once())->method('adicionar')->willReturnCallback(static function (DataComemorativa $d) use (&$criada): DataComemorativa {
            self::assertSame(['natal', true, 0, 'todos'], [$d->getChave(), $d->isLigada(), $d->getAntecedencia(), $d->getPublico()], 'nasce com o padrão');
            $criada = $d;

            return $d;
        });
        $this->repositorio->expects(self::once())->method('salvar');

        $linha = $this->alterar()->executar('natal', true, 1, 'prim', new User());

        self::assertInstanceOf(DataComemorativa::class, $criada);
        self::assertSame([1, 'prim'], [$criada->getAntecedencia(), $criada->getPublico()]);
        self::assertSame(['natal', 1, 'prim'], [$linha->chave, $linha->antecedencia, $linha->publico]);
    }

    #[TestDox('A chave fora das 17 não cria linha')]
    public function testForaDoCalendarioNaoCria(): void
    {
        $this->repositorio->expects(self::never())->method('adicionar');
        $this->expectExceptionMessage(AlterarDataComemorativaUseCase::DESCONHECIDA);

        $this->alterar()->executar('zzz', true, 0, 'todos', new User());
    }

    #[TestDox('O padrão das datas sem linha é o do dc, e é o mesmo dos valores iniciais da entidade')]
    public function testPadraoUnico(): void
    {
        $nova = new DataComemorativa('ano');

        self::assertSame(['ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'], DataComemorativa::PADRAO);
        self::assertSame(DataComemorativa::PADRAO, ['ligada' => $nova->isLigada(), 'antecedencia' => $nova->getAntecedencia(), 'publico' => $nova->getPublico()]);
        self::assertSame(DataComemorativa::PADRAO, DataComemorativa::estadoOuPadrao([], 'ano'));
    }

    private function alterar(): AlterarDataComemorativaUseCase
    {
        return new AlterarDataComemorativaUseCase($this->repositorio, new MockClock('2026-10-21 10:00', 'America/Sao_Paulo'));
    }
}
