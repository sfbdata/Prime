<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\TribunalCnjResolver;
use App\Processo\UseCase\CadastrarProcessoPelaPastaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CadastrarProcessoPelaPastaUseCase::class)]
#[CoversClass(CadastroDoProcessoInput::class)]
final class CadastrarProcessoPelaPastaUseCaseTest extends TestCase
{
    /** TJDFT (J=8, TR=07). */
    private const NUMERO = '0000001-00.2024.8.07.0001';
    private const DIGITOS = '00000010020248070001';

    private ProcessoRepository&MockObject $repositorio;
    private CadastrarProcessoPelaPastaUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(ProcessoRepository::class);
        $this->useCase = new CadastrarProcessoPelaPastaUseCase($this->repositorio, new TribunalCnjResolver());
        $this->tenant = new Tenant();
        $this->usuario = new User();
    }

    #[TestDox('cadastro completo: grava o número só com dígitos, a sigla derivada, os campos, as partes e as movimentações (linhas vazias ignoradas)')]
    public function testCadastroCompleto(): void
    {
        $this->repositorio->expects(self::once())->method('save')->with(self::isInstanceOf(Processo::class));

        $resultado = $this->useCase->executar($this->input([
            'situacaoProcesso' => 'SUSPENSO',
            'instancia' => 'G2',
            'dataDistribuicao' => '2024-03-05',
            'partes' => [['tipo' => 'ADVOGADO', 'nome' => 'Ana Souza', 'documento' => '', 'papel' => 'Polo ativo'], ['tipo' => '', 'nome' => '', 'documento' => '', 'papel' => '']],
            'movimentacoes' => [['dataMovimentacao' => '2024-04-01', 'descricao' => 'Juntada de petição', 'tipo' => '', 'orgao' => '2ª Vara'], ['dataMovimentacao' => '', 'descricao' => '', 'tipo' => '', 'orgao' => '']],
        ]), $this->tenant, $this->usuario);

        $processo = $resultado->processo;
        self::assertFalse($resultado->jaExistia);
        self::assertSame(self::DIGITOS, $processo->getNumeroProcesso());
        self::assertSame('TJDFT', $processo->getSiglaTribunal());
        self::assertSame('2ª VARA CÍVEL', $processo->getOrgaoJulgador(), 'a entidade guarda o órgão em caixa alta');
        self::assertSame('SUSPENSO', $processo->getSituacaoProcesso());
        self::assertSame('G2', $processo->getInstancia());
        self::assertSame('2024-03-05', $processo->getDataDistribuicao()?->format('Y-m-d'));
        self::assertSame($this->usuario, $processo->getCriadoPor());
        self::assertCount(1, $processo->getPartes());
        self::assertNull($processo->getPartes()->first()->getDocumento());
        self::assertCount(1, $processo->getMovimentacoes());
        self::assertNull($processo->getMovimentacoes()->first()->getTipo());
    }

    #[TestDox('situação e instância vazias viram "Em andamento" e "G1"; parte sem tipo vira "Parte"')]
    public function testPadroesDosCamposVazios(): void
    {
        $processo = $this->useCase->executar($this->input([
            'partes' => [['tipo' => '', 'nome' => 'Bruno Lima', 'documento' => '', 'papel' => '']],
        ]), $this->tenant, $this->usuario)->processo;

        self::assertSame('EM_ANDAMENTO', $processo->getSituacaoProcesso());
        self::assertSame('G1', $processo->getInstancia());
        self::assertSame('PARTE', $processo->getPartes()->first()->getTipo());
    }

    #[TestDox('número que já existe no escritório: devolve o existente, sem exigir nem aplicar o que foi digitado')]
    public function testNumeroExistenteDevolveOExistente(): void
    {
        $existente = (new Processo())->setNumeroProcesso(self::DIGITOS)->setOrgaoJulgador('Vara antiga');
        $this->repositorio->method('findByNumeroProcessoDoTenant')->with(self::DIGITOS, $this->tenant)->willReturn($existente);
        $this->repositorio->expects(self::never())->method('save');

        $resultado = $this->useCase->executar(new CadastroDoProcessoInput(self::NUMERO, orgaoJulgador: 'Outra vara'), $this->tenant, $this->usuario);

        self::assertTrue($resultado->jaExistia);
        self::assertSame($existente, $resultado->processo);
        self::assertSame('VARA ANTIGA', $existente->getOrgaoJulgador());
    }

    /** @param array<string, mixed> $campos */
    #[DataProvider('recusas')]
    #[TestDox('recusa com a mensagem e nada é gravado: $mensagem')]
    public function testRecusas(array $campos, string $mensagem): void
    {
        $this->repositorio->expects(self::never())->method('save');

        $this->expectException(DadosDoProcessoInvalidosException::class);
        $this->expectExceptionMessage($mensagem);

        $this->useCase->executar($this->input($campos), $this->tenant, $this->usuario);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function recusas(): iterable
    {
        yield 'número incompleto' => [['numeroProcesso' => '1234567-89.2024'], 'Informe o número completo no formato NNNNNNN-DD.AAAA.J.TT.OOOO.'];
        yield 'obrigatórios em falta' => [['orgaoJulgador' => '', 'assuntoProcessual' => ''], 'Preencha: órgão julgador, assunto processual.'];
        yield 'situação fora das opções' => [['situacaoProcesso' => 'QUALQUER'], 'Situação do processo inválida.'];
        yield 'instância fora das opções' => [['instancia' => 'G9'], 'Instância inválida.'];
        yield 'data impossível' => [['dataDistribuicao' => '2024-02-31'], 'Data de distribuição inválida.'];
        yield 'baixa antes da distribuição' => [['dataDistribuicao' => '2024-05-01', 'dataBaixa' => '2024-04-30'], 'A data de baixa não pode ser anterior à distribuição.'];
        yield 'órgão longo demais' => [['orgaoJulgador' => str_repeat('a', 256)], 'Órgão julgador passa de 255 caracteres.'];
        yield 'tipo de parte desconhecido' => [['partes' => [['tipo' => 'JUIZ', 'nome' => 'Fulano', 'documento' => '', 'papel' => '']]], 'Tipo de parte inválido.'];
        yield 'documento da parte longo demais' => [['partes' => [['tipo' => 'PARTE', 'nome' => 'Fulano', 'documento' => str_repeat('1', 31), 'papel' => '']]], 'Documento da parte passa de 30 caracteres.'];
        yield 'data da movimentação inválida' => [['movimentacoes' => [['dataMovimentacao' => '01/04/2024', 'descricao' => 'Juntada', 'tipo' => '', 'orgao' => '']]], 'Data de movimentação inválida.'];
    }

    #[TestDox('o DTO lê o formulário: campo mandado como lista vira vazio, linhas que não são lista somem')]
    public function testDtoToleraFormatoErrado(): void
    {
        $request = new \Symfony\Component\HttpFoundation\Request([], [
            'numeroProcesso' => ' ' . self::NUMERO . ' ',
            'orgaoJulgador' => ['x'],
            'partes' => ['texto', ['nome' => ' Ana ', 'tipo' => ['y']]],
            'movimentacoes' => 'texto',
        ]);

        $input = CadastroDoProcessoInput::fromRequest($request);

        self::assertSame(self::DIGITOS, $input->numeroSoDigitos());
        self::assertSame('', $input->orgaoJulgador);
        self::assertSame([['tipo' => '', 'nome' => 'Ana', 'documento' => '', 'papel' => '']], $input->partes);
        self::assertSame([], $input->movimentacoes);
    }

    /** @param array<string, mixed> $campos */
    private function input(array $campos): CadastroDoProcessoInput
    {
        $campos += [
            'numeroProcesso' => self::NUMERO,
            'orgaoJulgador' => '2ª Vara Cível',
            'classeProcessual' => 'Procedimento Comum Cível',
            'assuntoProcessual' => 'Indenização por Dano Moral',
        ];

        return new CadastroDoProcessoInput(...$campos);
    }
}
