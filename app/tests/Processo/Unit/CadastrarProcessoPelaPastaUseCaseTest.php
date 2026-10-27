<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\DTO\CadastroDoProcessoInput;
use App\Processo\Entity\Processo;
use App\Processo\Exception\DadosDoProcessoInvalidosException;
use App\Processo\Repository\ProcessoRepository;
use App\Processo\Service\DatajudProcessoMapper;
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
        $this->useCase = new CadastrarProcessoPelaPastaUseCase($this->repositorio, new TribunalCnjResolver(), new DatajudProcessoMapper($this->repositorio));
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

    #[TestDox('instância Juizado Especial (JE) é aceita e ganha o rótulo do desenho')]
    public function testJuizadoEspecialEhInstanciaValida(): void
    {
        $this->repositorio->expects(self::once())->method('save');

        $processo = $this->useCase->executar($this->input(['instancia' => 'JE']), $this->tenant, $this->usuario)->processo;

        self::assertSame('JE', $processo->getInstancia());
        self::assertSame('Juizado Especial', $processo->getInstanciaRotulo());
    }

    #[TestDox('com a consulta do CNJ: o mapper traz sigilo, sistema, id e assuntos; a janela vence nos campos; partes da janela; movimentação igual à do CNJ é reaproveitada')]
    public function testConsultaDoCnjEntraPrimeiroEAJanelaVence(): void
    {
        $consulta = [
            'id' => 'TJDFT_G1_x', 'nivelSigilo' => 2, 'sistema' => ['codigo' => 1, 'nome' => 'PJe'],
            'orgaoJulgador' => ['codigo' => 55, 'nome' => 'Vara do CNJ'],
            'assuntos' => [['codigo' => 1, 'nome' => 'Indenização']],
            'partes' => [['tipo' => 'PARTE', 'nome' => 'Parte que a pessoa tirou']],
            'movimentos' => [['codigo' => 26, 'nome' => 'Distribuído', 'dataHora' => '2024-03-05T10:00:00Z', 'orgaoJulgador' => ['codigo' => '55', 'nomeOrgao' => 'Vara do CNJ']]],
        ];

        $processo = $this->useCase->executar($this->input([
            'partes' => [['tipo' => 'PARTE', 'nome' => 'Ana Souza', 'documento' => '', 'papel' => '']],
            'movimentacoes' => [['dataMovimentacao' => '2024-03-05', 'descricao' => 'Distribuído', 'tipo' => '26', 'orgao' => 'VARA DO CNJ']],
        ]), $this->tenant, $this->usuario, $consulta)->processo;

        self::assertSame(2, $processo->getNivelSigilo());
        self::assertSame('PJe', $processo->getSistema());
        self::assertSame('TJDFT_G1_x', $processo->getDatajudId());
        self::assertSame($consulta, $processo->getDatajudRaw());
        self::assertCount(1, $processo->getAssuntos());
        self::assertSame('2ª VARA CÍVEL', $processo->getOrgaoJulgador(), 'a janela vence');
        self::assertSame(self::DIGITOS, $processo->getNumeroProcesso());
        self::assertSame(['ANA SOUZA'], array_values(array_map(static fn ($p) => $p->getNome(), $processo->getPartes()->toArray())));
        self::assertCount(1, $processo->getMovimentacoes());
        self::assertSame('55', $processo->getMovimentacoes()->first()->getOrgaoCodigo(), 'a do CNJ guarda o código do órgão');
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

    #[TestDox('número antigo fora do padrão CNJ que já existe no escritório continua sendo achado')]
    public function testNumeroAntigoForaDoPadraoExistente(): void
    {
        $antigo = (new Processo())->setNumeroProcesso('2008123');
        $this->repositorio->method('findByNumeroProcessoDoTenant')->with('2008123', $this->tenant)->willReturn($antigo);

        $resultado = $this->useCase->executar(new CadastroDoProcessoInput('2008.123'), $this->tenant, $this->usuario);

        self::assertTrue($resultado->jaExistia);
        self::assertSame($antigo, $resultado->processo);
    }

    #[TestDox('a janela não tem a data de baixa: vazia, fica a do CNJ')]
    public function testBaixaDoCnjFica(): void
    {
        $processo = $this->useCase->executar($this->input([]), $this->tenant, $this->usuario, ['dataBaixa' => '2023-01-10'])->processo;

        self::assertSame('2023-01-10', $processo->getDataBaixa()?->format('Y-m-d'));
    }

    #[TestDox('a baixa do CNJ também não pode ficar antes da distribuição da janela')]
    public function testBaixaDoCnjAntesDaDistribuicaoDaJanela(): void
    {
        $this->expectException(DadosDoProcessoInvalidosException::class);
        $this->expectExceptionMessage('A data de baixa não pode ser anterior à distribuição.');

        $this->useCase->executar($this->input(['dataDistribuicao' => '2024-05-01']), $this->tenant, $this->usuario, ['dataBaixa' => '2023-01-10']);
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
        yield 'número incompleto, na lista do desenho' => [['numeroProcesso' => '1234567-89.2024', 'classeProcessual' => ''], 'Preencha: número do processo, classe processual.'];
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
