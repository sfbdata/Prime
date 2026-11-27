<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Service\MontadorDeEmail;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Comercial\UseCase\SimularNovoCadastroUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MontadorDeEmail::class)]
#[CoversClass(SimularNovoCadastroUseCase::class)]
#[CoversClass(EnviarTesteDaCampanhaUseCase::class)]
final class NomeNoEmailTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'tudo maiúsculo' => ['FARLEI', 'Farlei'];
        yield 'tudo minúsculo' => ['ana', 'Ana'];
        yield 'misturado' => ['mArIa', 'Maria'];
        yield 'acento' => ['JOSÉ', 'José'];
        yield 'caixa mista escrita com cuidado' => ['McDonald', 'McDonald'];
        yield 'apóstrofo escrito com cuidado' => ["D'Ávila", "D'Ávila"];
        yield 'partícula no meio' => ['MARIA DA SILVA E SOUZA DOS SANTOS', 'Maria da Silva e Souza dos Santos'];
        yield 'partícula que abre o nome' => ['DE PAULA', 'De Paula'];
        yield 'uma palavra só' => ['DA', 'Da'];
        yield 'espaços sobrando' => ['  joão   do   carmo ', 'João do Carmo'];
    }

    #[TestDox('normalizarNome: "$entrada" vira "$esperado"')]
    #[DataProvider('nomes')]
    public function testNormalizarNome(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, MontadorDeEmail::normalizarNome($entrada));
    }

    #[TestDox('primeiroNome normaliza a caixa e sem nome devolve "colega"')]
    public function testPrimeiroNome(): void
    {
        self::assertSame('Farlei', MontadorDeEmail::primeiroNome('FARLEI SOUZA'));
        self::assertSame('colega', MontadorDeEmail::primeiroNome(null));
        self::assertSame('colega', MontadorDeEmail::primeiroNome('  '));
    }

    #[TestDox('M1 (A6): sem nome, primeiroNome devolve o texto do modelo (aparado); vazio volta a "colega"; com nome, o texto não entra')]
    public function testPrimeiroNomeComTextoDoModelo(): void
    {
        self::assertSame('Doutor(a)', MontadorDeEmail::primeiroNome(null, '  Doutor(a) '));
        self::assertSame('colega', MontadorDeEmail::primeiroNome('', '   '));
        self::assertSame('Ana', MontadorDeEmail::primeiroNome('ANA LIMA', 'Doutor(a)'));
        self::assertSame('Prezado(a), veja', MontadorDeEmail::trocarNome('{nome}, veja', MontadorDeEmail::primeiroNome(null, 'prezado(a)')));
    }

    #[TestDox('trocarNome: "Colega" com maiúscula quando {nome} abre o texto ou a frase, minúsculo no meio')]
    public function testTrocarNome(): void
    {
        self::assertSame('Colega, veja o que mudou', MontadorDeEmail::trocarNome('{nome}, veja o que mudou', 'colega'));
        self::assertSame('Olá, colega!', MontadorDeEmail::trocarNome('Olá, {nome}!', 'colega'));
        self::assertSame('<p>Colega, oi. Colega, tchau, colega</p>', MontadorDeEmail::trocarNome('<p>{nome}, oi. {nome}, tchau, {nome}</p>', 'colega'));
        self::assertSame('Ana, veja', MontadorDeEmail::trocarNome('{nome}, veja', 'Ana'));
    }

    #[TestDox('B3: a simulação do novo cadastro diz para onde foi, que leva "[TESTE]" e que nenhuma conta foi criada; teto de 30 por hora')]
    public function testMensagemDaSimulacao(): void
    {
        foreach ([SimularNovoCadastroUseCase::MENSAGEM, SimularNovoCadastroUseCase::MENSAGEM_SEM_BOAS_VINDAS] as $mensagem) {
            self::assertStringContainsString('Simulação enviada para %s', $mensagem);
            self::assertStringContainsString('"[TESTE]"', $mensagem);
            self::assertStringContainsString('Nenhuma conta foi criada', $mensagem);
        }
        self::assertSame('[TESTE] ', SimularNovoCadastroUseCase::PREFIXO);
        self::assertSame(30, SimularNovoCadastroUseCase::TETO_POR_HORA);
        self::assertStringContainsString('30', SimularNovoCadastroUseCase::TETO_ATINGIDO);
    }

    #[TestDox('O teto de envios de teste é de 30 por hora')]
    public function testTetoDeTestes(): void
    {
        self::assertSame(30, EnviarTesteDaCampanhaUseCase::TETO_POR_HORA);
        self::assertStringContainsString('30', EnviarTesteDaCampanhaUseCase::TETO_ATINGIDO);
    }
}
