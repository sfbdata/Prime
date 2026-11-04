<?php

declare(strict_types=1);

namespace App\Tests\Blog\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * O `builder-blog/laco.sh` (spec painel-artigos-blogs, seção 6.1) rodado de verdade com `sh`, com git, npm, rsync e ssh
 * falsos no PATH que só registram as chamadas. Prova os passos do contrato sem rede nem node: o pedido que passa
 * vira `no_ar` com o commit, o que falha não chega ao rsync, a pasta com ponto fica de fora e o `resultado.json` é
 * JSON válido mesmo com aspas, barras e quebras de linha no log.
 */
#[CoversNothing]
final class LacoDoBlogBuilderTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/laco-builder-' . bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir([$this->raiz . '/bin', $this->raiz . '/pedidos', $this->raiz . '/publicados', $this->raiz . '/cache', $this->raiz . '/tmp']);
        foreach (self::stubs() as $nome => $script) {
            $fs->dumpFile($this->raiz . '/bin/' . $nome, $script);
            $fs->chmod($this->raiz . '/bin/' . $nome, 0755);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->raiz);
    }

    #[TestDox('Pedido que passa: iniciado, npm test sem ARTIGOS, build com os artigos do pedido, rsync dos dois e no_ar com o commit')]
    public function testPedidoOkVaiAoAr(): void
    {
        $this->criarPedido('7');

        $this->rodar();

        $resultado = $this->resultado('7');
        self::assertSame(1, $resultado['versao']);
        self::assertSame(7, $resultado['id']);
        self::assertSame('no_ar', $resultado['situacao']);
        self::assertSame('abc1234', $resultado['commit']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $resultado['iniciadaEm']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $resultado['terminadaEm']);
        self::assertFileExists($this->raiz . '/pedidos/7/iniciado');
        self::assertFileDoesNotExist($this->raiz . '/pedidos/7/resultado.json.tmp');

        $chamadas = $this->chamadas();
        $pedido = $this->raiz . '/pedidos/7';
        foreach (['blog', 'blog-gratis'] as $blog) {
            self::assertContains("npm ci [$blog] ARTIGOS=", $chamadas);
            self::assertContains("npm test [$blog] ARTIGOS=", $chamadas);
            self::assertContains("npm run build [$blog] ARTIGOS=$pedido/artigos/$blog", $chamadas);
        }
        self::assertContains('git -C ' . $this->raiz . '/cache/repositorio.git fetch --quiet --prune origin +refs/heads/main:refs/heads/main', $chamadas);
        self::assertContains('GIT_SSH_COMMAND=ssh -i /chave-de-teste -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . $this->raiz . '/cache/known_hosts', $chamadas);
        $rsyncs = array_values(array_filter($chamadas, static fn (string $c): bool => str_starts_with($c, 'rsync ')));
        self::assertCount(2, $rsyncs);
        self::assertMatchesRegularExpression('#^rsync -a --delete .+/sites/blog/dist/ ' . preg_quote($this->raiz, '#') . '/publicados/blog/$#', $rsyncs[0]);
        self::assertMatchesRegularExpression('#^rsync -a --delete .+/sites/blog-gratis/dist/ ' . preg_quote($this->raiz, '#') . '/publicados/gratis/$#', $rsyncs[1]);
    }

    #[TestDox('npm test que falha vira falhou e nada vai ao rsync')]
    public function testNpmTestQueFalhaNaoPublica(): void
    {
        $this->criarPedido('3');

        $this->rodar(['FALHAR_NPM' => 'test']);

        $resultado = $this->resultado('3');
        self::assertSame('falhou', $resultado['situacao']);
        self::assertSame('abc1234', $resultado['commit']);
        self::assertStringContainsString('falhou: blog', $resultado['log']);
        self::assertSame([], array_filter($this->chamadas(), static fn (string $c): bool => str_starts_with($c, 'rsync ')));
        self::assertNotContains('npm run build [blog] ARTIGOS=' . $this->raiz . '/pedidos/3/artigos/blog', $this->chamadas());
    }

    #[TestDox('git fetch que falha vira falhou sem commit, e o laço termina normalmente')]
    public function testFetchQueFalhaViraFalhou(): void
    {
        $this->criarPedido('4');

        $processo = $this->rodar(['FALHAR_GIT' => 'fetch']);

        self::assertSame(0, $processo->getExitCode());
        $resultado = $this->resultado('4');
        self::assertSame('falhou', $resultado['situacao']);
        self::assertNull($resultado['commit']);
        self::assertSame([], array_filter($this->chamadas(), static fn (string $c): bool => str_starts_with($c, 'npm ')));
    }

    #[TestDox('Pasta com ponto (montagem em andamento) é ignorada, e o menor id numérico vem primeiro')]
    public function testPastaComPontoIgnoradaEMenorIdPrimeiro(): void
    {
        (new Filesystem())->mkdir($this->raiz . '/pedidos/.2.montando/artigos/blog');
        $this->criarPedido('10');
        $this->criarPedido('9');

        $this->rodar();

        self::assertFileDoesNotExist($this->raiz . '/pedidos/.2.montando/iniciado');
        self::assertFileDoesNotExist($this->raiz . '/pedidos/.2.montando/resultado.json');
        self::assertSame('no_ar', $this->resultado('9')['situacao']);
        self::assertFileDoesNotExist($this->raiz . '/pedidos/10/resultado.json', '--uma-vez processa só um pedido');
    }

    #[TestDox('Só a pasta com ponto: nada é processado')]
    public function testSoPastaComPontoNaoFazNada(): void
    {
        (new Filesystem())->mkdir($this->raiz . '/pedidos/.5.montando/artigos/blog');

        $this->rodar();

        self::assertSame([], $this->chamadas());
        self::assertFileDoesNotExist($this->raiz . '/pedidos/.5.montando/resultado.json');
    }

    #[TestDox('Pedido que já tem resultado.json não é refeito')]
    public function testPedidoComResultadoNaoERefeito(): void
    {
        $this->criarPedido('1');
        (new Filesystem())->dumpFile($this->raiz . '/pedidos/1/resultado.json', '{}');

        $this->rodar();

        self::assertSame([], $this->chamadas());
    }

    #[TestDox('O log com aspas, barra invertida, tab e quebras de linha sai num resultado.json válido')]
    public function testLogComCaracteresEspeciaisViraJsonValido(): void
    {
        $this->criarPedido('12');

        $this->rodar(['FALHAR_NPM' => 'ci']);

        $resultado = $this->resultado('12');
        self::assertSame('falhou', $resultado['situacao']);
        self::assertStringContainsString("erro \"aspas\" e \\barra\\ com\ttab\nsegunda linha: ação", $resultado['log']);
    }

    #[TestDox('As cores ANSI do npm (ESC[...m e o "[32m" que sobra) saem do log')]
    public function testCoresAnsiSaemDoLog(): void
    {
        $this->criarPedido('13');

        $this->rodar();

        $log = $this->resultado('13')['log'];
        self::assertStringContainsString('verde e sobra', $log);
        self::assertStringNotContainsString("\e", $log);
        self::assertDoesNotMatchRegularExpression('/\[\d+m/', $log);
    }

    #[TestDox('Pedido apagado pelo BlueJus durante a geração não vai ao ar')]
    public function testPedidoApagadoAntesDoRsyncNaoPublica(): void
    {
        $this->criarPedido('14');

        $processo = $this->rodar(['APAGAR_PEDIDO' => $this->raiz . '/pedidos/14']);

        self::assertDirectoryDoesNotExist($this->raiz . '/pedidos/14');
        self::assertSame([], array_filter($this->chamadas(), static fn (string $c): bool => str_starts_with($c, 'rsync ')));
        self::assertStringContainsString('pedido 14', $processo->getOutput() . $processo->getErrorOutput());
    }

    #[TestDox('rsync do gratis que falha depois do rsync do blog: o resultado é falhou')]
    public function testRsyncDoGratisQueFalhaViraFalhou(): void
    {
        $this->criarPedido('15');

        $this->rodar(['FALHAR_RSYNC' => 'gratis']);

        $resultado = $this->resultado('15');
        self::assertSame('falhou', $resultado['situacao']);
        self::assertStringContainsString('falhou: rsync gratis', $resultado['log']);
        self::assertCount(2, array_filter($this->chamadas(), static fn (string $c): bool => str_starts_with($c, 'rsync ')));
    }

    #[TestDox('Blog sem artigos no pacote: ARTIGOS aponta para a pasta vazia e o laço repassa normalmente')]
    public function testPastaDeBlogVaziaERepassada(): void
    {
        $this->criarPedido('16');
        $vazia = $this->raiz . '/pedidos/16/artigos/blog-gratis';
        self::assertSame([], array_values(array_diff((array) scandir($vazia), ['.', '..'])));

        $this->rodar();

        self::assertContains('npm run build [blog-gratis] ARTIGOS=' . $vazia, $this->chamadas());
        self::assertSame('no_ar', $this->resultado('16')['situacao']);
    }

    #[TestDox('Pedido cujo resultado não pôde ser gravado vai para IGNORADOS: o laço segue para o próximo e não o refaz')]
    public function testPedidoSemResultadoGravadoNaoERefeito(): void
    {
        $this->criarPedido('5');
        $this->criarPedido('6');
        $quebrado = $this->raiz . '/pedidos/5';

        $processo = $this->processo(['QUEBRAR_PEDIDO' => $quebrado, 'INTERVALO' => '0.1'], false);
        $processo->start();
        $limite = microtime(true) + 15;
        while (!is_file($this->raiz . '/pedidos/6/resultado.json') && microtime(true) < $limite) {
            usleep(50_000);
        }
        usleep(800_000); // várias voltas do laço (INTERVALO 0,1 s) depois do pedido 6
        $processo->stop(2);

        self::assertSame('no_ar', $this->resultado('6')['situacao']);
        self::assertFileDoesNotExist($quebrado . '/resultado.json');
        $builds = array_filter($this->chamadas(), static fn (string $c): bool => $c === "npm run build [blog] ARTIGOS=$quebrado/artigos/blog");
        self::assertCount(1, $builds, 'o pedido 5 foi gerado mais de uma vez');
        self::assertStringContainsString('pedido 5: erro ao processar', $processo->getErrorOutput());
    }

    #[TestDox('Com a trava ocupada por outro builder, o segundo laço sai sem processar nada')]
    public function testTravaOcupadaNaoProcessa(): void
    {
        $this->criarPedido('8');
        $trava = fopen($this->raiz . '/pedidos/.trava', 'c');
        self::assertNotFalse($trava);
        self::assertTrue(flock($trava, \LOCK_EX | \LOCK_NB));

        try {
            $processo = $this->processo();
            $processo->run();
        } finally {
            flock($trava, \LOCK_UN);
            fclose($trava);
        }

        self::assertFalse($processo->isSuccessful());
        self::assertStringContainsString('outro builder já está rodando', $processo->getErrorOutput());
        self::assertSame([], $this->chamadas());
        self::assertFileDoesNotExist($this->raiz . '/pedidos/8/iniciado');
    }

    private function criarPedido(string $id): void
    {
        $fs = new Filesystem();
        $fs->dumpFile($this->raiz . "/pedidos/$id/pedido.json", '{"versao":1,"id":' . $id . '}');
        $fs->dumpFile($this->raiz . "/pedidos/$id/artigos/blog/um-artigo.md", "---\ntitulo: Um\n---\n");
        $fs->mkdir($this->raiz . "/pedidos/$id/artigos/blog-gratis");
    }

    /** @param array<string, string> $env */
    private function rodar(array $env = []): Process
    {
        $processo = $this->processo($env);
        $processo->run();

        self::assertTrue($processo->isSuccessful(), $processo->getErrorOutput() . $processo->getOutput());

        return $processo;
    }

    /** @param array<string, string> $env */
    private function processo(array $env = [], bool $umaVez = true): Process
    {
        $comando = ['sh', \dirname(__DIR__, 4) . '/builder-blog/laco.sh'];
        if ($umaVez) {
            $comando[] = '--uma-vez';
        }
        $processo = new Process($comando, null, [
            'PATH' => $this->raiz . '/bin:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'PEDIDOS' => $this->raiz . '/pedidos',
            'PUBLICADOS' => $this->raiz . '/publicados',
            'CACHE' => $this->raiz . '/cache',
            'CHAVE' => '/chave-de-teste',
            'TMPDIR' => $this->raiz . '/tmp',
            'CHAMADAS' => $this->raiz . '/chamadas.txt',
            // O laço tem que tirar o ARTIGOS herdado: os testes do repositório rodam sem ele.
            'ARTIGOS' => '/nao-pode-chegar-ao-npm-test',
            'FALHAR_NPM' => '',
            'FALHAR_GIT' => '',
            'FALHAR_RSYNC' => '',
            'APAGAR_PEDIDO' => '',
            'QUEBRAR_PEDIDO' => '',
            ...$env,
        ]);
        $processo->setTimeout(30);

        return $processo;
    }

    /** @return array<string, mixed> */
    private function resultado(string $id): array
    {
        $arquivo = $this->raiz . "/pedidos/$id/resultado.json";
        self::assertFileExists($arquivo);
        $dados = json_decode((string) file_get_contents($arquivo), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($dados);

        return $dados;
    }

    /** @return list<string> */
    private function chamadas(): array
    {
        $arquivo = $this->raiz . '/chamadas.txt';

        return is_file($arquivo) ? file($arquivo, \FILE_IGNORE_NEW_LINES) ?: [] : [];
    }

    /** @return array<string, string> */
    private static function stubs(): array
    {
        return [
            // clone cria a cópia com os dois blogs; rev-parse devolve o commit; FALHAR_GIT=<subcomando> falha.
            'git' => <<<'SH'
                #!/bin/sh
                echo "git $*" >> "$CHAMADAS"
                echo "GIT_SSH_COMMAND=$GIT_SSH_COMMAND" >> "$CHAMADAS"
                for a in "$@"; do
                    if [ -n "$FALHAR_GIT" ] && [ "$a" = "$FALHAR_GIT" ]; then echo "fatal: $a falhou" >&2; exit 128; fi
                done
                case " $* " in
                    *" clone "*)
                        for a in "$@"; do destino=$a; done
                        mkdir -p "$destino/blog/dist" "$destino/blog-gratis/dist" ;;
                    *" rev-parse "*) echo abc1234 ;;
                esac
                exit 0
                SH,
            // Registra subcomando, pasta e ARTIGOS; FALHAR_NPM=<subcomando> falha com um log cheio de caracteres especiais.
            'npm' => <<<'SH'
                #!/bin/sh
                if [ "$1" = run ]; then sub="run $2"; else sub=$1; fi
                echo "npm $sub [$(basename "$PWD")] ARTIGOS=${ARTIGOS:-}" >> "$CHAMADAS"
                echo "saída do npm $sub"
                printf '\033[32mverde\033[0m e [33msobra[0m\n'
                # APAGAR_PEDIDO=<pasta>: o BlueJus apaga o pedido (deu como falho) durante o build do blog-gratis.
                if [ -n "$APAGAR_PEDIDO" ] && [ "$sub" = "run build" ] && [ "$(basename "$PWD")" = blog-gratis ]; then
                    rm -rf "$APAGAR_PEDIDO"
                fi
                # QUEBRAR_PEDIDO=<pasta>: resultado.json.tmp vira pasta, e o resultado não pode ser gravado.
                if [ -n "$QUEBRAR_PEDIDO" ] && [ "$sub" = "run build" ]; then
                    case "${ARTIGOS:-}" in "$QUEBRAR_PEDIDO"/*) mkdir -p "$QUEBRAR_PEDIDO/resultado.json.tmp" ;; esac
                fi
                if [ -n "$FALHAR_NPM" ] && [ "$1" = "$FALHAR_NPM" ]; then
                    printf 'erro "aspas" e \\barra\\ com\ttab\r\nsegunda linha: ação\n' >&2
                    exit 1
                fi
                exit 0
                SH,
            // FALHAR_RSYNC=<destino>: falha o rsync para sites-publicados/<destino>/.
            'rsync' => <<<'SH'
                #!/bin/sh
                echo "rsync $*" >> "$CHAMADAS"
                for a in "$@"; do destino=$a; done
                if [ -n "$FALHAR_RSYNC" ]; then
                    case "$destino" in */"$FALHAR_RSYNC"/) echo "rsync: falhou" >&2; exit 23 ;; esac
                fi
                exit 0
                SH,
            'ssh' => <<<'SH'
                #!/bin/sh
                echo "ssh $*" >> "$CHAMADAS"
                exit 0
                SH,
        ];
    }
}
