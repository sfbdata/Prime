<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\Replica\ReplicadorDeArquivos;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Categoria desligada não sobe ao R2, nem pela fila: o worker só reivindica linhas das categorias
 * ligadas. A linha da desligada fica pendente, intocada, e volta a andar quando a categoria religar.
 */
#[CoversClass(ReplicadorDeArquivos::class)]
#[CoversClass(OutboxDeReplicacao::class)]
final class CategoriaDesligadaNaFilaTest extends KernelTestCase
{
    private Connection $conexao;
    private ArmazenamentoEmMemoria $local;
    private EspelhoRemotoEmMemoria $espelho;
    private OutboxDeReplicacao $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->local   = new ArmazenamentoEmMemoria();
        $this->espelho = new EspelhoRemotoEmMemoria();
        $this->outbox  = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-24 12:00:00', 'UTC'));
    }

    #[TestDox('o worker leva só a categoria ligada; a desligada fica pendente e anda quando religar')]
    public function testDesligadaFicaPendenteAteReligar(): void
    {
        $foto = $this->naFila(CategoriaDeArquivo::FOTO_PERFIL, EscopoDeArquivo::global(), 'f.jpg');
        $doc  = $this->naFila(CategoriaDeArquivo::CLIENTE_DOCUMENTO, EscopoDeArquivo::deTenant(7), 'd.pdf');

        $resumo = $this->replicador('foto_perfil')->processar($this->espelho, 10);

        self::assertSame(1, $resumo->enviados);
        self::assertNotNull($this->espelho->consultar($foto));
        self::assertNull($this->espelho->consultar($doc), 'categoria desligada não sobe');
        self::assertSame(['pendente', 0], $this->estadoDe($doc));

        self::assertSame(0, $this->replicador('')->processar($this->espelho, 10)->reivindicados, 'tudo desligado: nada sai da fila');

        $religada = $this->replicador('foto_perfil,cliente_documento')->processar($this->espelho, 10);
        self::assertSame(1, $religada->enviados);
        self::assertNotNull($this->espelho->consultar($doc));
    }

    #[TestDox('a reivindicação filtra na consulta: com só a desligada na fila, não entrega nada')]
    public function testReivindicacaoFiltraPorCategoria(): void
    {
        $this->naFila(CategoriaDeArquivo::CLIENTE_DOCUMENTO, EscopoDeArquivo::deTenant(7), 'd.pdf');

        self::assertNull($this->outbox->reivindicarProxima(900, 6, ['foto_perfil']));
        self::assertNull($this->outbox->reivindicarProxima(900, 6, []));
        self::assertNotNull($this->outbox->reivindicarProxima(900, 6, ['cliente_documento']));
    }

    private function naFila(CategoriaDeArquivo $categoria, EscopoDeArquivo $escopo, string $nome): ChaveDeArquivo
    {
        $chave = new ChaveDeArquivo($escopo, $categoria, $nome);
        $this->local->gravar($chave, FonteDeConteudo::deTexto('conteudo ' . $nome));
        $this->outbox->registrarEnvio($chave, hash('sha256', 'conteudo ' . $nome), \strlen('conteudo ' . $nome), 'application/octet-stream');

        return $chave;
    }

    /** @return array{0: string, 1: int} */
    private function estadoDe(ChaveDeArquivo $chave): array
    {
        $linha = (array) $this->conexao->fetchAssociative(
            'SELECT estado, tentativas FROM armazenamento_replicacao WHERE categoria = ? AND nome = ?',
            [$chave->categoria->value, $chave->nome],
        );

        return [(string) $linha['estado'], (int) $linha['tentativas']];
    }

    private function replicador(string $ligadas): ReplicadorDeArquivos
    {
        return new ReplicadorDeArquivos($this->outbox, $this->local, new LoggerEmMemoria(), new PoliticaDeEspelhamento($ligadas));
    }
}
