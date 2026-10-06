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
            if (is_file($caminho)) {
                @unlink($caminho);
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
