<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Armazenamento\ResolvedorDeCaminhoLocal;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A política de falha da remoção física depois da transação (E2.5): o banco já decidiu, então
 * nada aqui lança, cada arquivo é tentado por conta própria, e o que ficar tem rastro no log.
 */
#[CoversClass(RemocaoAposTransacao::class)]
final class RemocaoAposTransacaoTest extends TestCase
{
    private ArmazenamentoEmMemoria $armazenamento;
    private LoggerEmMemoria $logger;
    private RemocaoAposTransacao $remocao;

    protected function setUp(): void
    {
        $this->armazenamento = new ArmazenamentoEmMemoria();
        $this->logger        = new LoggerEmMemoria();
        $this->remocao       = new RemocaoAposTransacao($this->armazenamento, $this->logger);
    }

    #[TestDox('remove todas as chaves e conta só o que existia')]
    public function testRemoveEContaSoOQueExistia(): void
    {
        $a = $this->chave('a.pdf');
        $b = $this->chave('b.pdf');
        $this->armazenamento->semear($a);
        $this->armazenamento->semear($b);

        $resultado = $this->remocao->remover([$a, $b, $this->chave('ausente.pdf')], 'teste');

        self::assertSame(2, $resultado->removidos);
        self::assertTrue($resultado->completa());
        self::assertFalse($this->armazenamento->existe($a));
        self::assertFalse($this->armazenamento->existe($b));
        self::assertSame([], $this->logger->registros);
    }

    #[TestDox('laço com falha no meio: os outros saem, a falha fica registrada e nada é lançado')]
    public function testFalhaNoMeioDoLacoNaoInterrompeOsDemais(): void
    {
        [$a, $b, $c] = [$this->chave('a.pdf'), $this->chave('b.pdf'), $this->chave('c.pdf')];
        foreach ([$a, $b, $c] as $chave) {
            $this->armazenamento->semear($chave);
        }
        $this->armazenamento->falhaAoExcluir = static fn (ChaveDeArquivo $chave): ?\Throwable => $chave->nome === 'b.pdf'
            ? new FalhaDeArmazenamento('disco recusou b.pdf')
            : null;

        $resultado = $this->remocao->remover([$a, $b, $c], 'ExcluirSecaoUseCase');

        self::assertSame(2, $resultado->removidos);
        self::assertSame([$b->comoTexto()], $resultado->naoRemovidas);
        self::assertFalse($this->armazenamento->existe($a));
        self::assertTrue($this->armazenamento->existe($b), 'a que falhou fica — órfão recuperável');
        self::assertFalse($this->armazenamento->existe($c), 'a falha da anterior não impediu esta');

        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame('ExcluirSecaoUseCase', $erros[0]['contexto']['contexto']);
        self::assertSame($b->comoTexto(), $erros[0]['contexto']['chave']);
        self::assertSame('disco recusou b.pdf', $erros[0]['contexto']['erro']);
    }

    #[TestDox('chave adiada que o VO recusa vira registro, e as demais seguem')]
    public function testChaveAdiadaRecusadaViraRegistro(): void
    {
        $boa = $this->chave('boa.pdf');
        $this->armazenamento->semear($boa);

        $resultado = $this->remocao->remover(
            [
                fn (): ChaveDeArquivo => $this->chave("nome/com/barra"),
                $boa,
            ],
            'foto anterior',
        );

        self::assertSame(1, $resultado->removidos);
        self::assertSame(['(chave não montada)'], $resultado->naoRemovidas);
        self::assertSame(ChaveDeArquivoInvalida::class, $this->logger->doNivel('error')[0]['contexto']['classe']);
    }

    /**
     * No disco de verdade: a versão anterior do `excluir()` voltava calada com o diretório
     * ilegível. Agora a remoção registra — o órfão não fica invisível.
     */
    #[TestDox('disco ilegível depois do COMMIT vira registro, e não silêncio')]
    public function testDiscoIlegivelViraRegistro(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        $raiz    = sys_get_temp_dir() . '/e25-remocao-' . bin2hex(random_bytes(6));
        $local   = new ArmazenamentoLocal(new ResolvedorDeCaminhoLocal(
            $raiz . '/pastas',
            $raiz . '/clientes',
            $raiz . '/chamados',
            $raiz . '/justificativas',
            $raiz . '/perfil',
            $raiz . '/cobrancas',
            $raiz . '/kanban',
        ));
        $remocao = new RemocaoAposTransacao($local, $this->logger);
        $chave   = $this->chave('a.pdf');
        $local->gravar($chave, FonteDeConteudo::deTexto('x'));
        chmod($raiz, 0o000);

        try {
            $resultado = $remocao->remover([$chave], 'teste');
            chmod($raiz, 0o755);

            self::assertSame(0, $resultado->removidos);
            self::assertSame([$chave->comoTexto()], $resultado->naoRemovidas);
            self::assertCount(1, $this->logger->doNivel('error'));
            self::assertTrue($local->existe($chave));
        } finally {
            chmod($raiz, 0o755);
            @unlink($raiz . '/clientes/a.pdf');
            @rmdir($raiz . '/clientes');
            @rmdir($raiz);
        }
    }

    #[TestDox('logger que lança não faz a remoção lançar: a falha continua no resultado e as demais chaves seguem')]
    public function testLoggerQueFalhaNaoMudaODesfecho(): void
    {
        [$a, $b] = [$this->chave('a.pdf'), $this->chave('b.pdf')];
        $this->armazenamento->semear($a);
        $this->armazenamento->semear($b);
        $this->armazenamento->falhaAoExcluir = static fn (ChaveDeArquivo $chave): ?\Throwable => $chave->nome === 'a.pdf'
            ? new FalhaDeArmazenamento('disco recusou a.pdf')
            : null;
        $this->logger->falhar = true;

        $resultado = $this->remocao->remover([$a, $b], 'teste');
        $this->remocao->preservar([$a], 'teste', 'incerta');

        self::assertSame(1, $resultado->removidos, 'a chave depois da falha também saiu');
        self::assertSame([$a->comoTexto()], $resultado->naoRemovidas);
        self::assertCount(2, $this->logger->registros, 'as duas tentativas de registro aconteceram');
    }

    #[TestDox('preservar não toca o storage e deixa rastro')]
    public function testPreservarSoRegistra(): void
    {
        $chave = $this->chave('fica.pdf');
        $this->armazenamento->semear($chave);

        $this->remocao->preservar([$chave], 'lote', 'destino da transação: incerta');

        self::assertTrue($this->armazenamento->existe($chave));
        self::assertSame([], $this->armazenamento->excluidas);
        $avisos = $this->logger->doNivel('warning');
        self::assertCount(1, $avisos);
        self::assertSame($chave->comoTexto(), $avisos[0]['contexto']['chave']);
        self::assertSame('destino da transação: incerta', $avisos[0]['contexto']['motivo']);
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::CLIENTE_DOCUMENTO, $nome);
    }
}
