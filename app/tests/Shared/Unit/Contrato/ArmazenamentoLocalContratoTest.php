<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Contrato;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O backend de disco cumprindo o contrato, contra arquivos de verdade.
 *
 * Usa diretórios temporários próprios em vez do container: o que se prova aqui é o COMPORTAMENTO
 * do backend. Que os caminhos de produção continuam sendo os mesmos é outra prova, e ela mora em
 * `MapaDeChaveParaCaminhoLocalTest` — misturar as duas faria nenhuma das duas ser confiável.
 */
#[CoversClass(ArmazenamentoLocal::class)]
final class ArmazenamentoLocalContratoTest extends ArmazenamentoContratoTestCase
{
    protected string $raiz;
    private ArmazenamentoLocal $backend;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir() . '/e2-contrato-' . bin2hex(random_bytes(6));

        $this->backend = new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            uploadsDir: $this->raiz . '/pastas',
            clientesUploadsDir: $this->raiz . '/clientes',
            chamadosUploadsDir: $this->raiz . '/chamados',
            justificativasUploadsDir: $this->raiz . '/justificativas',
            fotosPerfilDir: $this->raiz . '/perfil',
            cobrancasUploadsDir: $this->raiz . '/cobrancas',
            kanbanUploadsDir: $this->raiz . '/kanban',
        ));
    }

    protected function tearDown(): void
    {
        $this->removerArvore($this->raiz);
    }

    protected function backend(): ArmazenamentoDeArquivos
    {
        return $this->backend;
    }

    /**
     * A promessa que só o backend de disco pode quebrar: "não consegui ler" NÃO é "não existe".
     *
     * Testa o avô, não o pai — a versão anterior só olhava o diretório imediato, e com o avô sem
     * `+x` o próprio `is_dir()` do pai falhava, a guarda não disparava e o método devolvia false
     * em silêncio. O incidente é plausível aqui: `public/uploads/*` nascendo com uid errado é
     * recorrente neste projeto, e `public/uploads/cobrancas` é o avô de uma categoria inteira.
     */
    #[TestDox('existe() LANÇA quando um diretório ancestral não é legível')]
    public function testExisteLancaQuandoAncestralNaoEhLegivel(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório; o guarda não é observável');
        }

        $chave = $this->chave('escondido.pdf');
        $this->backend->gravar($chave, FonteDeConteudo::deTexto('x'));
        self::assertTrue($this->backend->existe($chave));

        chmod($this->raiz, 0o000); // o AVÔ de clientes/escondido.pdf

        try {
            $this->expectException(FalhaDeArmazenamento::class);
            $this->backend->existe($chave);
        } finally {
            chmod($this->raiz, 0o755);
        }
    }

    /**
     * A escrita é atômica por temporário + rename. Se algo falhar no meio, o `.parcial-` não pode
     * ficar no volume persistido: ele viraria órfão que nenhuma rotina varre, e a E3 o contaria
     * como lixo real.
     */
    #[TestDox('gravação interrompida não deixa arquivo .parcial- para trás')]
    public function testFalhaNaGravacaoNaoDeixaParcial(): void
    {
        $chave = $this->chave('interrompido.bin');

        $streamMorto = fopen('php://temp', 'w+b');
        $fonte       = FonteDeConteudo::deStream($streamMorto);
        fclose($streamMorto);

        try {
            $this->backend->gravar($chave, $fonte);
        } catch (\Throwable) {
            // esperado
        }

        $sobras = glob($this->raiz . '/clientes/*.parcial-*') ?: [];
        self::assertSame([], $sobras, 'temporário de escrita vazou para o volume persistido');
    }

    /**
     * Os dois ramos de publicação (rename rápido × cópia em stream) têm de deixar o MESMO modo.
     * Qual deles roda depende do sistema de arquivos (EXDEV), então um modo divergente daria
     * arquivo ora legível, ora não, sem nada no código explicando.
     */
    #[TestDox('os dois caminhos de gravação publicam com o mesmo modo de arquivo')]
    public function testModoDeArquivoEhDeterministico(): void
    {
        $origem = $this->arquivoTemporarioCom('vindo de fora');
        chmod($origem, 0o600); // tempnam nasce assim

        $rapido = $this->chave('pelo-rename.bin');
        $lento  = $this->chave('pelo-stream.bin');

        $this->backend->gravar($rapido, FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: true));
        $this->backend->gravar($lento, FonteDeConteudo::deTexto('vindo de fora'));

        $esperado = 0o666 & ~umask();

        self::assertSame($esperado, $this->modoDe($this->raiz . '/clientes/pelo-rename.bin'));
        self::assertSame($esperado, $this->modoDe($this->raiz . '/clientes/pelo-stream.bin'));
    }

    private function modoDe(string $caminho): int
    {
        clearstatcache(true, $caminho);

        return (int) (fileperms($caminho) & 0o777);
    }

    private function removerArvore(string $caminho): void
    {
        if (!is_dir($caminho)) {
            return;
        }

        foreach (scandir($caminho) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $filho = $caminho . '/' . $entrada;
            is_dir($filho) ? $this->removerArvore($filho) : @unlink($filho);
        }

        @rmdir($caminho);
    }
}
