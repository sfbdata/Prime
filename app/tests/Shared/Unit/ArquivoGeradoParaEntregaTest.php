<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use App\Shared\Armazenamento\DiretorioTemporarioPrivado;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O arquivo gerado (o .zip) sobrevive à liberação da área em que nasceu, só pode ter nascido numa
 * área, e some quando quem o entrega decide — nunca por destrutor.
 */
#[CoversClass(ArquivoGeradoParaEntrega::class)]
final class ArquivoGeradoParaEntregaTest extends TestCase
{
    private const FINALIDADE = 'testegerado';

    /** @var list<string> */
    private array $paraApagar = [];

    protected function tearDown(): void
    {
        foreach ($this->paraApagar as $caminho) {
            if (is_link($caminho) || is_file($caminho)) {
                @unlink($caminho);

                continue;
            }
            if (is_dir($caminho)) {
                exec('rm -rf ' . escapeshellarg($caminho));
            }
        }
    }

    #[TestDox('retirarDa move o arquivo para o diretório privado do processo, com a extensão; a área pode ser liberada sem levá-lo')]
    public function testRetiraDaArea(): void
    {
        $area    = AreaTemporariaPrivada::criar(self::FINALIDADE);
        $naArea  = $area->gravar('conteúdo do zip', 'zip');

        $gerado             = ArquivoGeradoParaEntrega::retirarDa($area, $naArea, self::FINALIDADE);
        $this->paraApagar[] = $gerado->caminho();

        self::assertFileDoesNotExist($naArea, 'saiu da área');
        self::assertFileExists($gerado->caminho());
        self::assertSame(DiretorioTemporarioPrivado::doProcesso(self::FINALIDADE)->caminho(), \dirname($gerado->caminho()));
        self::assertStringEndsWith('.zip', $gerado->caminho());
        self::assertSame('conteúdo do zip', file_get_contents($gerado->caminho()));
        self::assertSame(0o600, fileperms($gerado->caminho()) & 0o777);

        $area->liberar();
        self::assertFileExists($gerado->caminho(), 'a liberação da área não alcança o que saiu dela');

        $gerado->descartar();
        self::assertFileDoesNotExist($gerado->caminho());
        $gerado->descartar(); // idempotente
    }

    #[TestDox('extensão que não serve vira .bin')]
    public function testExtensaoEstranhaViraBin(): void
    {
        $area   = AreaTemporariaPrivada::criar(self::FINALIDADE);
        $naArea = $area->caminho() . '/saida';
        self::assertNotFalse(file_put_contents($naArea, 'x'));

        try {
            $gerado             = ArquivoGeradoParaEntrega::retirarDa($area, $naArea, self::FINALIDADE);
            $this->paraApagar[] = $gerado->caminho();

            self::assertStringEndsWith('.bin', $gerado->caminho());
        } finally {
            $area->liberar();
        }
    }

    #[TestDox('recusa caminho FORA da área, arquivo inexistente e link simbólico: só o que nasceu na área sai dela')]
    public function testRecusaOQueNaoEhDaArea(): void
    {
        $area = AreaTemporariaPrivada::criar(self::FINALIDADE);
        $fora = tempnam(sys_get_temp_dir(), 'fora-');
        self::assertNotFalse($fora);
        $this->paraApagar[] = $fora;

        try {
            try {
                ArquivoGeradoParaEntrega::retirarDa($area, $fora, self::FINALIDADE);
                self::fail('caminho fora da área tem de ser recusado');
            } catch (FalhaNoTemporario) {
            }
            self::assertFileExists($fora, 'o arquivo de fora não foi tocado');

            try {
                ArquivoGeradoParaEntrega::retirarDa($area, $area->caminho() . '/nao-existe.zip', self::FINALIDADE);
                self::fail('arquivo inexistente tem de ser recusado');
            } catch (FalhaNoTemporario) {
            }

            $link = $area->caminho() . '/link.zip';
            if (@symlink($fora, $link)) {
                try {
                    ArquivoGeradoParaEntrega::retirarDa($area, $link, self::FINALIDADE);
                    self::fail('link simbólico tem de ser recusado');
                } catch (FalhaNoTemporario) {
                }
                self::assertFileExists($fora, 'o alvo do link não foi movido');
            }
        } finally {
            $area->liberar();
        }
    }

    #[TestDox('limparSobras: área e arquivo retirado com mais de 1 h somem; os recentes ficam; nome que não é do mecanismo não é tocado')]
    public function testLimparSobras(): void
    {
        $dir   = DiretorioTemporarioPrivado::doProcesso('testesobras')->caminho();
        $velho = time() - 2 * ArquivoGeradoParaEntrega::IDADE_DE_SOBRA_SEGUNDOS;

        $areaVelha = $dir . '/' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($areaVelha, 0o700));
        self::assertNotFalse(file_put_contents($areaVelha . '/documentos.zip', 'parcial'));
        self::assertTrue(touch($areaVelha . '/documentos.zip', $velho));
        self::assertTrue(touch($areaVelha, $velho));
        $this->paraApagar[] = $areaVelha;

        $areaRecente = $dir . '/' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($areaRecente, 0o700));
        $this->paraApagar[] = $areaRecente;

        $zipVelho = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';
        self::assertNotFalse(file_put_contents($zipVelho, 'x'));
        self::assertTrue(touch($zipVelho, $velho));
        $this->paraApagar[] = $zipVelho;

        $zipRecente = $dir . '/' . bin2hex(random_bytes(8)) . '.zip';
        self::assertNotFalse(file_put_contents($zipRecente, 'x'));
        $this->paraApagar[] = $zipRecente;

        $estranho = $dir . '/estranho-velho.txt';
        self::assertNotFalse(file_put_contents($estranho, 'x'));
        self::assertTrue(touch($estranho, $velho));
        $this->paraApagar[] = $estranho;

        $removidas = ArquivoGeradoParaEntrega::limparSobras('testesobras');

        self::assertGreaterThanOrEqual(2, $removidas);
        self::assertDirectoryDoesNotExist($areaVelha, 'área de montagem morta, com mais de 1 h: sai inteira');
        self::assertFileDoesNotExist($zipVelho, 'entrega que não aconteceu, com mais de 1 h: sai');
        self::assertDirectoryExists($areaRecente, 'montagem em curso: fica');
        self::assertFileExists($zipRecente, 'entrega em curso: fica');
        self::assertFileExists($estranho, 'só o que o mecanismo cria é candidato');
    }

    #[TestDox('limparSobras não segue link: link velho no nível de cima fica, link dentro de área velha sai como link — o alvo nunca é tocado')]
    public function testLimparSobrasNaoSegueLink(): void
    {
        $dir   = DiretorioTemporarioPrivado::doProcesso('testesobras')->caminho();
        $velho = time() - 2 * ArquivoGeradoParaEntrega::IDADE_DE_SOBRA_SEGUNDOS;

        $alvoDir = sys_get_temp_dir() . '/alvo-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($alvoDir, 0o700));
        $this->paraApagar[] = $alvoDir;
        $alvoArquivo = $alvoDir . '/importante.txt';
        self::assertNotFalse(file_put_contents($alvoArquivo, 'x'));
        self::assertTrue(touch($alvoArquivo, $velho));
        self::assertTrue(touch($alvoDir, $velho));

        $linkTopo = $dir . '/' . bin2hex(random_bytes(8)); // nome de área, mas é link para fora
        if (!@symlink($alvoDir, $linkTopo)) {
            self::markTestSkipped('sem symlink neste sistema');
        }
        $this->paraApagar[] = $linkTopo;

        $areaVelha = $dir . '/' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($areaVelha, 0o700));
        self::assertTrue(symlink($alvoArquivo, $areaVelha . '/x.zip'));
        self::assertTrue(symlink($alvoDir, $areaVelha . '/sub'));
        self::assertTrue(touch($areaVelha, $velho));
        $this->paraApagar[] = $areaVelha;

        ArquivoGeradoParaEntrega::limparSobras('testesobras');

        self::assertFileExists($alvoArquivo, 'o alvo do link não foi tocado');
        self::assertDirectoryExists($alvoDir);
        self::assertTrue(is_link($linkTopo), 'link no nível de cima: nem seguido, nem removido');
        self::assertDirectoryDoesNotExist($areaVelha, 'a área velha saiu, com os links dentro dela removidos como links');
    }

    #[TestDox('não há destrutor que apague: o objeto sair de escopo não remove o arquivo')]
    public function testSemDestrutorQueApague(): void
    {
        self::assertFalse((new \ReflectionClass(ArquivoGeradoParaEntrega::class))->hasMethod('__destruct'));

        $area    = AreaTemporariaPrivada::criar(self::FINALIDADE);
        $caminho = ArquivoGeradoParaEntrega::retirarDa($area, $area->gravar('x', 'zip'), self::FINALIDADE)->caminho();
        $this->paraApagar[] = $caminho;
        $area->liberar();
        gc_collect_cycles();

        self::assertFileExists($caminho);
    }
}
