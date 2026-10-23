<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Tests\Shared\Paralelo\ProcessoDeTesteParalelo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ProcessoDeTesteParalelo::class)]
final class ProcessoDeTesteParaleloTest extends TestCase
{
    #[TestDox('fora do ParaTest nada muda, mesmo com o TEST_TOKEN de uma frente')]
    public function testSerialNaoMexeEmNada(): void
    {
        self::assertSame([], ProcessoDeTesteParalelo::variaveis(['TEST_TOKEN' => 'minha-frente'], '/tmp'));
        self::assertSame([], ProcessoDeTesteParalelo::variaveis([], '/tmp'));
    }

    #[TestDox('o banco de um processo nunca é o banco serial de uma frente válida')]
    public function testProcessoNaoTomaOBancoDeOutraFrente(): void
    {
        // O banco serial de uma frente é saas_test<frente>, e frente só tem minúsculas.
        foreach (['', 'a', 'a_p1'] as $frente) {
            $token = ProcessoDeTesteParalelo::variaveis(['PARATEST' => '1', 'TEST_TOKEN' => '1', 'TEST_FRENTE' => $frente], '/tmp')['TEST_TOKEN'];
            self::assertSame(0, preg_match('#^[a-z0-9][a-z0-9._/-]*$#', $token), $token . ' poderia ser o nome de uma frente');
        }
    }

    #[TestDox('no checkout principal o token é só o número do processo')]
    public function testCheckoutPrincipal(): void
    {
        self::assertSame([
            'TEST_TOKEN'     => '_P3',
            'TESTE_PROCESSO' => '-p3',
            'TMPDIR'         => '/tmp/jusprime-teste-_P3',
            'TMP'            => '/tmp/jusprime-teste-_P3',
            'TEMP'           => '/tmp/jusprime-teste-_P3',
        ], ProcessoDeTesteParalelo::variaveis(['PARATEST' => '1', 'TEST_TOKEN' => '3'], '/tmp/'));
    }

    #[TestDox('numa frente o token leva o nome dela, e a barra do nome não vira subdiretório no temporário')]
    public function testFrente(): void
    {
        $variaveis = ProcessoDeTesteParalelo::variaveis(
            ['PARATEST' => '1', 'TEST_TOKEN' => '12', 'TEST_FRENTE' => 'eng/cache.v2'],
            '/var/tmp',
        );

        self::assertSame('eng/cache.v2_P12', $variaveis['TEST_TOKEN']);
        self::assertSame('-p12', $variaveis['TESTE_PROCESSO']);
        self::assertSame('/var/tmp/jusprime-teste-eng+cache.v2_P12', $variaveis['TMPDIR']);
    }

    #[TestDox('processos diferentes, ou frentes diferentes, nunca dividem token nem temporário')]
    public function testTokensNaoColidem(): void
    {
        $tokens      = [];
        $temporarios = [];
        foreach (['', 'a', 'a-p1', 'a_p1', 'eng/cache', 'eng-cache', 'b'] as $frente) {
            foreach (['1', '2', '11'] as $numero) {
                $v = ProcessoDeTesteParalelo::variaveis(['PARATEST' => '1', 'TEST_TOKEN' => $numero, 'TEST_FRENTE' => $frente], '/tmp');
                $tokens[]      = $v['TEST_TOKEN'];
                $temporarios[] = $v['TMPDIR'];
            }
        }

        self::assertSame($tokens, array_unique($tokens));
        self::assertSame($temporarios, array_unique($temporarios));
    }

    #[TestDox('a lista de diretórios que o bootstrap cria é a mesma do when@test do services.yaml')]
    public function testDiretoriosBatemComOServicesYaml(): void
    {
        $config     = Yaml::parseFile(\dirname(__DIR__, 3) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        $parametros = $config['when@test']['parameters'] ?? [];
        $prefixo    = '%kernel.project_dir%/var/uploads-test%env(default::TESTE_PROCESSO)%/';

        $doYaml = [];
        foreach ($parametros as $valor) {
            if (\is_string($valor) && str_starts_with($valor, $prefixo)) {
                $doYaml[] = substr($valor, \strlen($prefixo));
            }
        }
        sort($doYaml);

        self::assertNotSame([], $doYaml, 'o services.yaml não tem mais os uploads de teste com o sufixo do processo');
        self::assertSame(ProcessoDeTesteParalelo::DIRETORIOS_DE_UPLOAD, $doYaml);
    }

    #[TestDox('cria os diretórios de upload com a sentinela, e repetir não estraga nada')]
    public function testPrepararUploads(): void
    {
        $raiz = sys_get_temp_dir() . '/processo-paralelo-' . bin2hex(random_bytes(6));

        try {
            ProcessoDeTesteParalelo::prepararUploads($raiz);
            file_put_contents($raiz . '/pastas/do-teste.txt', 'x');
            ProcessoDeTesteParalelo::prepararUploads($raiz);

            foreach (ProcessoDeTesteParalelo::DIRETORIOS_DE_UPLOAD as $nome) {
                self::assertFileExists($raiz . '/' . $nome . '/' . ProcessoDeTesteParalelo::SENTINELA);
            }
            self::assertFileExists($raiz . '/pastas/do-teste.txt', 'preparar de novo não pode apagar o que já estava lá');
            self::assertFalse(@rmdir($raiz . '/pastas'), 'com a sentinela, o rmdir de quem limpa a raiz não a apaga');
        } finally {
            foreach (ProcessoDeTesteParalelo::DIRETORIOS_DE_UPLOAD as $nome) {
                foreach (array_diff(scandir($raiz . '/' . $nome) ?: [], ['.', '..']) as $arquivo) {
                    unlink($raiz . '/' . $nome . '/' . $arquivo);
                }
                rmdir($raiz . '/' . $nome);
            }
            rmdir($raiz);
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function ambientesInvalidos(): iterable
    {
        yield 'sem número' => [['PARATEST' => '1']];
        yield 'número zero' => [['PARATEST' => '1', 'TEST_TOKEN' => '0']];
        yield 'token de frente no lugar do número' => [['PARATEST' => '1', 'TEST_TOKEN' => 'minha-frente']];
        yield 'frente com maiúscula' => [['PARATEST' => '1', 'TEST_TOKEN' => '1', 'TEST_FRENTE' => 'a_P1']];
        yield 'frente que sobe diretório' => [['PARATEST' => '1', 'TEST_TOKEN' => '1', 'TEST_FRENTE' => '../x']];
    }

    /** @param array<string, string> $ambiente */
    #[DataProvider('ambientesInvalidos')]
    #[TestDox('recusa ambiente que poderia juntar dois processos no mesmo banco: $_dataName')]
    public function testRecusaAmbienteInvalido(array $ambiente): void
    {
        $this->expectException(\RuntimeException::class);

        ProcessoDeTesteParalelo::variaveis($ambiente, '/tmp');
    }
}
