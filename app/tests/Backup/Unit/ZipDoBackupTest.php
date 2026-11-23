<?php

declare(strict_types=1);

namespace App\Tests\Backup\Unit;

use App\Backup\Service\ZipDoBackup;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O ZIP do backup (spec backup, seções 4 e 5): nomes seguros e únicos, teto, ausentes no LEIA-ME, manifesto. */
#[CoversClass(ZipDoBackup::class)]
final class ZipDoBackupTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private AreaTemporariaPrivada $area;
    private string $caminho;

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->area          = AreaTemporariaPrivada::criar('testebackup');
        $this->caminho       = $this->area->caminho() . '/backup.zip';
    }

    protected function tearDown(): void
    {
        $this->area->liberar();
    }

    private function chave(string $nome, string $conteudo = 'conteudo'): ChaveDeArquivo
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
        $this->armazenamento->semear($chave, $conteudo);

        return $chave;
    }

    private function novo(int $teto = ZipDoBackup::TETO_DE_BYTES): ZipDoBackup
    {
        return ZipDoBackup::criar($this->caminho, $this->armazenamento, 'backup-bluejus-2026-11-23', $teto);
    }

    private function fechar(ZipDoBackup $zip): \ZipArchive
    {
        $quem = (new User())->setFullName('Ana Souza')->setEmail('ana@exemplo.test');
        $tenant = (new Tenant())->setName('Escritório Exemplo');
        $zip->finalizar($quem, $tenant, new \DateTimeImmutable('2026-11-23 10:30:00'));

        $aberto = new \ZipArchive();
        self::assertTrue($aberto->open($this->caminho));

        return $aberto;
    }

    #[TestDox('Nomes iguais no mesmo diretório ganham sufixo; nome com barra e ".." não escapa da raiz')]
    public function testNomesSegurosEUnicos(): void
    {
        $zip = $this->novo();
        $dir = $zip->reservarDiretorio('', 'pastas');
        $a   = $zip->adicionarArquivo($dir, 'peca.pdf', $this->chave('a'));
        $b   = $zip->adicionarArquivo($dir, 'PECA.pdf', $this->chave('b'));
        $c   = $zip->adicionarArquivo($dir, '../../etc/passwd', $this->chave('c'));
        $d   = $zip->reservarDiretorio('pastas', '../fora');

        self::assertSame('pastas/peca.pdf', $a);
        self::assertSame('pastas/PECA (2).pdf', $b);
        self::assertSame(1, substr_count((string) $c, '/'));
        self::assertStringStartsWith('pastas/', $d);
        self::assertStringNotContainsString('..', $d);

        $aberto = $this->fechar($zip);
        for ($i = 0; $i < $aberto->numFiles; ++$i) {
            $nome = (string) $aberto->getNameIndex($i);
            self::assertStringStartsWith('backup-bluejus-2026-11-23/', $nome);
            self::assertStringNotContainsString('..', $nome);
        }
        self::assertNotFalse($aberto->locateName('backup-bluejus-2026-11-23/pastas/PECA (2).pdf'));
    }

    #[TestDox('O que passa do teto fica fora e vai ao LEIA-ME; o que cabe entra')]
    public function testTetoDeBytes(): void
    {
        $zip = $this->novo(10);
        $um  = $zip->adicionarArquivo('', 'um.txt', $this->chave('um', '123456'));
        $dois = $zip->adicionarArquivo('', 'dois.txt', $this->chave('dois', '123456'));
        $tres = $zip->adicionarArquivo('', 'tres.txt', $this->chave('tres', '123'));

        self::assertSame('um.txt', $um);
        self::assertNull($dois);
        self::assertSame('tres.txt', $tres);
        self::assertSame(2, $zip->arquivosIncluidos());
        self::assertSame(1, $zip->arquivosFora());
        self::assertSame(2, $zip->emprestimosAbertos(), 'a libzip só lê no close: os empréstimos vivem até lá');

        $aberto = $this->fechar($zip);
        self::assertSame(0, $zip->emprestimosAbertos());
        self::assertFalse($aberto->locateName('backup-bluejus-2026-11-23/dois.txt'));
        $leia = (string) $aberto->getFromName('backup-bluejus-2026-11-23/LEIA-ME.txt');
        self::assertStringContainsString('dois.txt: ' . ZipDoBackup::MOTIVO_TETO, $leia);
    }

    #[TestDox('Arquivo não encontrado e chave legada inválida vão ao LEIA-ME e não derrubam')]
    public function testNaoEncontradoVaiAoLeiaMe(): void
    {
        $zip = $this->novo();
        $ausente = new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, 'nao-existe');

        self::assertNull($zip->adicionarArquivo('d', 'sumiu.pdf', $ausente));
        self::assertNull($zip->adicionarArquivo('d', 'legado.pdf', static fn (): ChaveDeArquivo => throw new ChaveDeArquivoInvalida('caminho ruim')));
        self::assertNotNull($zip->adicionarArquivo('d', 'ok.pdf', $this->chave('ok')));

        $aberto = $this->fechar($zip);
        $leia   = (string) $aberto->getFromName('backup-bluejus-2026-11-23/LEIA-ME.txt');
        self::assertStringContainsString('d/sumiu.pdf: ' . ZipDoBackup::MOTIVO_NAO_ENCONTRADO, $leia);
        self::assertStringContainsString('d/legado.pdf: ' . ZipDoBackup::MOTIVO_CAMINHO_INVALIDO, $leia);
        self::assertStringContainsString('Ana Souza (ana@exemplo.test)', $leia);
        self::assertStringContainsString('Escritório Exemplo', $leia);
        self::assertStringContainsString('Arquivos que ficaram de fora: 2', $leia);
    }

    #[TestDox('O manifesto lista cada arquivo com tamanho e sha256, e o JSON de dados também')]
    public function testManifesto(): void
    {
        $zip = $this->novo();
        $zip->adicionarArquivo('docs', 'a.txt', $this->chave('a', 'abc'));
        $zip->adicionarJson('docs/dados.json', ['nome' => 'João', 'url' => 'a/b']);
        $zip->contar('Pastas', 2);

        $aberto    = $this->fechar($zip);
        $manifesto = json_decode((string) $aberto->getFromName('backup-bluejus-2026-11-23/manifesto.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(ZipDoBackup::VERSAO_DO_FORMATO, $manifesto['versao_do_formato']);
        $porCaminho = array_column($manifesto['arquivos'], null, 'caminho');
        self::assertSame(hash('sha256', 'abc'), $porCaminho['docs/a.txt']['sha256']);
        self::assertSame(3, $porCaminho['docs/a.txt']['tamanho_bytes']);
        self::assertSame('arquivo', $porCaminho['docs/a.txt']['tipo']);
        self::assertSame('dados', $porCaminho['docs/dados.json']['tipo']);
        self::assertStringContainsString('João', (string) $aberto->getFromName('backup-bluejus-2026-11-23/docs/dados.json'));
        self::assertStringContainsString('Pastas: 2', (string) $aberto->getFromName('backup-bluejus-2026-11-23/LEIA-ME.txt'));
    }

    #[TestDox('Um arquivo do usuário chamado LEIA-ME.txt na raiz não sobrescreve o LEIA-ME do backup')]
    public function testLeiaMeReservado(): void
    {
        $zip = $this->novo();
        self::assertSame('LEIA-ME (2).txt', $zip->adicionarArquivo('', 'LEIA-ME.txt', $this->chave('l')));
        self::assertSame('manifesto (2).json', $zip->adicionarArquivo('', 'manifesto.json', $this->chave('m')));
    }

    #[TestDox('Formato já comprimido vai em CM_STORE')]
    public function testStoreParaJaComprimido(): void
    {
        $zip = $this->novo();
        $zip->adicionarArquivo('', 'a.pdf', $this->chave('a', str_repeat('x', 200)));
        $zip->adicionarArquivo('', 'b.txt', $this->chave('b', str_repeat('x', 200)));

        $aberto = $this->fechar($zip);
        self::assertSame(\ZipArchive::CM_STORE, $aberto->statName('backup-bluejus-2026-11-23/a.pdf')['comp_method']);
        self::assertSame(\ZipArchive::CM_DEFLATE, $aberto->statName('backup-bluejus-2026-11-23/b.txt')['comp_method']);
    }
}
