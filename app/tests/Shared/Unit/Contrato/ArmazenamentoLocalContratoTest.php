<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Contrato;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;

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
     * A mesma promessa, agora para quem LÊ (E2.4B, D13). Antes da E2.4B, `ler()` e `abrir()` só
     * perguntavam `is_file()`: com o avô ilegível respondiam "não encontrado", e o export de peça
     * transformaria a pane em 404.
     *
     * @param 'ler'|'abrir' $metodo
     */
    #[TestDox('$metodo() LANÇA falha, e não "não encontrado", quando um ancestral não é legível')]
    #[TestWith(['ler'])]
    #[TestWith(['abrir'])]
    public function testLeituraLancaFalhaQuandoAncestralNaoEhLegivel(string $metodo): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório; o guarda não é observável');
        }

        $chave = $this->chave('escondido.pdf');
        $this->backend->gravar($chave, FonteDeConteudo::deTexto('x'));

        chmod($this->raiz, 0o000); // o AVÔ de clientes/escondido.pdf

        try {
            $this->backend->{$metodo}($chave);
            self::fail('a leitura devia ter lançado');
        } catch (FalhaDeArmazenamento $e) {
            self::assertStringContainsString('não é legível', $e->getMessage());
        } finally {
            chmod($this->raiz, 0o755);
        }
    }

    /**
     * O outro lado da distinção: com a cadeia legível, ausência continua sendo ausência.
     *
     * @param 'ler'|'abrir' $metodo
     */
    #[TestDox('$metodo() de chave ausente num diretório legível lança ArquivoNaoEncontrado')]
    #[TestWith(['ler'])]
    #[TestWith(['abrir'])]
    public function testLeituraDeAusenteComCadeiaLegivelEhNaoEncontrado(string $metodo): void
    {
        $this->backend->gravar($this->chave('vizinho.pdf'), FonteDeConteudo::deTexto('x'));

        $this->expectException(ArquivoNaoEncontrado::class);

        $this->backend->{$metodo}($this->chave('nunca-gravado.pdf'));
    }

    /**
     * Origem EMPRESTADA (D15, acervo do operador): gravar sem `consumirOrigem` só LÊ a origem.
     *
     * `assertFileExists` não bastaria — um `rename()` para o destino e uma cópia de volta
     * deixariam o arquivo "existindo" com outro inode, outro modo e outro mtime. Aqui a origem é
     * fotografada inteira antes e depois, no modo em que um operador a deixaria (`0640`), e o
     * teste roda com o default e com o `false` explícito.
     */
    #[TestDox('gravar sem consumirOrigem não altera a origem: conteúdo, inode, modo e mtime')]
    public function testOrigemEmprestadaFicaIntacta(): void
    {
        foreach ([false, null] as $consumir) {
            $origem = $this->arquivoTemporarioCom(random_bytes(4096));
            chmod($origem, 0o640);
            touch($origem, 1_600_000_000);
            clearstatcache();
            $antes = $this->foto($origem);

            $fonte = $consumir === null
                ? FonteDeConteudo::deArquivoLocal($origem)
                : FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: $consumir);

            $gravado = $this->backend->gravar($this->novo('bin'), $fonte);

            clearstatcache();
            self::assertSame($antes, $this->foto($origem), 'a origem emprestada mudou');
            self::assertSame(
                $antes['sha256'],
                hash('sha256', $this->backend->ler($gravado->chave)),
                'o conteúdo gravado difere da origem',
            );
            self::assertSame($antes['tamanho'], $gravado->tamanhoBytes);

            @unlink($origem);
        }
    }

    /** @return array{ino: int, modo: int, mtime: int, tamanho: int, sha256: string} */
    private function foto(string $caminho): array
    {
        $stat = stat($caminho);
        self::assertIsArray($stat);

        return [
            'ino'     => $stat['ino'],
            'modo'    => $stat['mode'] & 0o7777,
            'mtime'   => $stat['mtime'],
            'tamanho' => $stat['size'],
            'sha256'  => (string) hash_file('sha256', $caminho),
        ];
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
     * O modo que o rename leva depende de onde a origem estava (no mesmo sistema de arquivos ele
     * preserva o 0600 do `tempnam()`), então um modo divergente daria arquivo ora legível, ora
     * não, sem nada no código explicando.
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

    /**
     * O caminho rápido continua existindo: no mesmo sistema de arquivos a origem é MOVIDA (mesmo
     * inode, pelos dois renames), não copiada. Guarda contra "resolver" a atomicidade relendo o
     * arquivo sempre.
     */
    #[TestDox('origem no mesmo dispositivo é movida por rename, sem cópia')]
    public function testOrigemNoMesmoDispositivoEhMovidaSemCopia(): void
    {
        $origem = $this->arquivoTemporarioCom('movido inteiro');
        $inode  = fileinode($origem);

        $this->backend->gravar($this->chave('movido.bin'), FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: true));

        $destino = $this->raiz . '/clientes/movido.bin';
        clearstatcache();
        self::assertSame($inode, fileinode($destino), 'o arquivo foi copiado onde podia ser movido');
        self::assertFileDoesNotExist($origem);
    }

    /**
     * O `rename()` do PHP não falha entre sistemas de arquivos: ele copia por dentro, **direto no
     * nome que recebeu**. Se esse nome fosse o final, numa sobrescrita a cópia truncaria e
     * reescreveria o inode do arquivo que já estava publicado — quem o lia via o conteúdo pela
     * metade.
     *
     * O vínculo físico (`link()`) torna isso observável sem depender de tempo: ele aponta para o
     * inode ANTIGO. Com a publicação atômica (vizinho `.parcial-` + rename), o inode antigo nunca é
     * tocado e o vínculo continua com o conteúdo antigo. Com a cópia no lugar, o vínculo passa a
     * mostrar o conteúdo novo.
     *
     * Precisa de dois dispositivos: o destino mora em `var/` e a origem vem do primeiro candidato
     * que esteja em outro — `sys_get_temp_dir()` ou `/dev/shm` (medido no container: 100, 204 e
     * 2096 para `var/`). É o mesmo arranjo da produção: upload em `/tmp`, uploads num volume.
     */
    #[TestDox('sobrescrita com origem em outro dispositivo não reescreve o arquivo publicado no lugar')]
    public function testOrigemEmOutroDispositivoNaoReescreveOInodePublicado(): void
    {
        $raizEmOutroDisco = \dirname(__DIR__, 4) . '/var/e2-dispositivo-' . bin2hex(random_bytes(6));
        mkdir($raizEmOutroDisco, 0o755, true);

        $origem = $this->origemEmOutroDispositivoQue($raizEmOutroDisco, 'conteúdo novo');

        try {
            if ($origem === null) {
                self::markTestSkipped('nenhum diretório gravável em outro dispositivo que var/ (tentados: sys_get_temp_dir(), /dev/shm)');
            }

            $backend = new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
                uploadsDir: $raizEmOutroDisco . '/pastas',
                clientesUploadsDir: $raizEmOutroDisco . '/clientes',
                chamadosUploadsDir: $raizEmOutroDisco . '/chamados',
                justificativasUploadsDir: $raizEmOutroDisco . '/justificativas',
                fotosPerfilDir: $raizEmOutroDisco . '/perfil',
                cobrancasUploadsDir: $raizEmOutroDisco . '/cobrancas',
                kanbanUploadsDir: $raizEmOutroDisco . '/kanban',
            ));

            $chave = $this->chave('publicado.pdf');
            $backend->gravar($chave, FonteDeConteudo::deTexto('conteúdo antigo'));

            $publicado = $raizEmOutroDisco . '/clientes/publicado.pdf';
            $vinculo   = $raizEmOutroDisco . '/clientes/vinculo-do-antigo';
            self::assertTrue(link($publicado, $vinculo));

            $backend->gravar($chave, FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: true));

            self::assertSame('conteúdo novo', file_get_contents($publicado));
            self::assertSame(
                'conteúdo antigo',
                file_get_contents($vinculo),
                'o arquivo publicado foi reescrito no lugar — a cópia entre dispositivos não foi atômica',
            );
            self::assertFileDoesNotExist($origem);
            self::assertSame([], glob($raizEmOutroDisco . '/clientes/*.parcial-*') ?: []);
        } finally {
            if ($origem !== null) {
                @unlink($origem);
            }
            $this->removerArvore($raizEmOutroDisco);
        }
    }

    /**
     * O ramo de falha do caminho rápido: a origem já foi para o vizinho e o destino não aceita a
     * publicação (é um diretório). O conteúdo tem de voltar para a origem, o caminho lento também
     * falha, e nada fica para trás — nem perdido, nem `.parcial-` no volume.
     */
    #[TestDox('destino impublicável: a gravação falha, a origem volta intacta e não sobra .parcial-')]
    public function testDestinoImpublicavelDevolveAOrigem(): void
    {
        mkdir($this->raiz . '/clientes/ocupado.pdf', 0o755, true); // um diretório no lugar do arquivo
        $origem = $this->arquivoTemporarioCom('conteúdo que não pode sumir');

        try {
            $this->backend->gravar($this->chave('ocupado.pdf'), FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: true));
            self::fail('A publicação sobre um diretório foi aceita.');
        } catch (FalhaDeArmazenamento) {
            // esperado
        }

        try {
            self::assertStringEqualsFile($origem, 'conteúdo que não pode sumir');
            self::assertSame([], glob($this->raiz . '/clientes/*.parcial-*') ?: []);
        } finally {
            @unlink($origem);
        }
    }

    /**
     * A falha realista NO MEIO da gravação de uma origem EMPRESTADA (D15): a cópia para o
     * `.parcial-` termina e a publicação é recusada (um diretório no lugar do destino). A origem só
     * foi lida — mesmo inode, modo e mtime — e nada fica para trás. Com `consumirOrigem: true` o
     * caminho rápido a moveria e acertaria o modo dela antes de tentar publicar; este teste cai.
     */
    #[TestDox('D15: publicação que falha com origem emprestada não toca na origem nem deixa .parcial-')]
    public function testPublicacaoQueFalhaNaoTocaNaOrigemEmprestada(): void
    {
        mkdir($this->raiz . '/clientes/ocupado.pdf', 0o755, true); // um diretório no lugar do arquivo
        $origem = $this->arquivoTemporarioCom(random_bytes(2048));
        chmod($origem, 0o640);
        touch($origem, 1_600_000_000);
        clearstatcache();
        $antes = $this->foto($origem);

        // Na mesma partição, mover e devolver preserva inode e data; o que denuncia o atalho é o modo
        // que o backend acerta (`0666 & ~umask`). Com umask 022 ele vira 0644, diferente do 0640.
        $umaskAnterior = umask(0o022);

        try {
            $falhou = false;
            try {
                $this->backend->gravar(
                    $this->chave('ocupado.pdf'),
                    FonteDeConteudo::deArquivoLocal($origem, consumirOrigem: false),
                );
            } catch (FalhaDeArmazenamento) {
                $falhou = true;
            }

            clearstatcache();
            self::assertTrue($falhou, 'A publicação sobre um diretório foi aceita.');
            self::assertSame($antes, $this->foto($origem), 'a origem emprestada mudou');
            self::assertSame([], glob($this->raiz . '/clientes/*.parcial-*') ?: []);
        } finally {
            umask($umaskAnterior);
            @unlink($origem);
        }
    }

    private function origemEmOutroDispositivoQue(string $destino, string $conteudo): ?string
    {
        $dispositivo = stat($destino)['dev'];

        foreach ([sys_get_temp_dir(), '/dev/shm'] as $candidato) {
            if (!is_dir($candidato) || !is_writable($candidato) || stat($candidato)['dev'] === $dispositivo) {
                continue;
            }

            $caminho = tempnam($candidato, 'contrato-origem-');
            if ($caminho === false || \dirname($caminho) !== rtrim($candidato, '/')) {
                continue; // tempnam caiu em silêncio no temporário do sistema
            }

            file_put_contents($caminho, $conteudo);

            return $caminho;
        }

        return null;
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
