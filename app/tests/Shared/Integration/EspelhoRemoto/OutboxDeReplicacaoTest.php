<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A outbox no PostgreSQL de verdade (spec P2 §14): estados, ordem por chave, posse, transação.
 *
 * Concorrência entre PROCESSOS (dois workers em conexões diferentes) não é reproduzível dentro do
 * rollback por teste do DAMA — o que se prova aqui é a REGRA (uma chave nunca tem duas linhas em
 * processamento; a mais nova supera a mais velha) que a trava consultiva + `SKIP LOCKED` aplicam.
 */
#[CoversClass(OutboxDeReplicacao::class)]
final class OutboxDeReplicacaoTest extends KernelTestCase
{
    private Connection $conexao;
    private MockClock $relogio;
    private OutboxDeReplicacao $outbox;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->relogio = new MockClock('2026-10-15 12:00:00');
        $this->outbox  = new OutboxDeReplicacao($this->conexao, $this->relogio);
    }

    #[TestDox('registrar envio cria linha pendente com chave remota, SHA, tamanho e MIME; exclusão nasce "registrado"')]
    public function testRegistrar(): void
    {
        $envio    = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $exclusao = $this->outbox->registrarExclusao($this->chave('b.pdf'));

        $linha = $this->linha($envio);
        self::assertSame('enviar', $linha['operacao']);
        self::assertSame('pendente', $linha['estado']);
        self::assertSame('core/t/1/uploads/pastas/a.pdf', $linha['chave_remota']);
        self::assertSame($this->sha('a'), $linha['sha256']);
        self::assertSame(1, (int) $linha['tamanho_bytes']);
        self::assertSame('pasta_documento', $linha['categoria']);
        self::assertSame(1, (int) $linha['escopo_tenant_id']);
        self::assertSame('registrado', $this->linha($exclusao)['estado']);
        self::assertSame('exclusao_registrada', $this->linha($exclusao)['operacao']);
    }

    #[TestDox('o nome entra na coluna byte a byte — Unicode, espaço na borda e ／')]
    public function testNomeByteAByte(): void
    {
        $nome = "0a096d7867417fae4a72dbd852cf4944. Aaaaaaa AÇAÇ 208／2024-1 ";
        $id   = $this->outbox->registrarEnvio($this->chave($nome), $this->sha('x'), 1, 'application/pdf');

        self::assertSame($nome, $this->linha($id)['nome']);
        self::assertSame('core/t/1/uploads/pastas/' . $nome, $this->linha($id)['chave_remota']);
    }

    #[TestDox('reivindicar: entrega o pendente em "processando", 1ª tentativa; com a posse válida, não entrega de novo')]
    public function testReivindicar(): void
    {
        $id   = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $item = $this->outbox->reivindicarProxima(900);

        self::assertNotNull($item);
        self::assertSame($id, $item->id);
        self::assertSame(1, $item->tentativas);
        self::assertSame('processando', $this->linha($id)['estado']);
        self::assertNull($this->outbox->reivindicarProxima(900), 'com posse válida a linha não sai para outro worker');
    }

    #[TestDox('queda do worker: posse vencida devolve a linha à fila, como 2ª tentativa')]
    public function testPosseVencida(): void
    {
        $id = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->outbox->reivindicarProxima(900);

        $this->relogio->sleep(901);
        $item = $this->outbox->reivindicarProxima(900);

        self::assertNotNull($item);
        self::assertSame($id, $item->id);
        self::assertSame(2, $item->tentativas);
    }

    #[TestDox('duas linhas da mesma chave: a mais velha vira "superado" e só a mais nova sai')]
    public function testMaisNovaSuperaAMaisVelha(): void
    {
        $velha = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('v1'), 2, 'application/pdf');
        $nova  = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('v2'), 2, 'application/pdf');

        $item = $this->outbox->reivindicarProxima(900);

        self::assertSame($nova, $item?->id);
        self::assertSame('superado', $this->linha($velha)['estado']);
        self::assertStringContainsString('#' . $nova, (string) $this->linha($velha)['ultimo_erro']);
    }

    #[TestDox('exclusão registrada DEPOIS de um envio pendente: o envio vira "superado" e nada sai — o excluído não é recriado')]
    public function testExclusaoSuperaEnvioAnterior(): void
    {
        $envio = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->outbox->registrarExclusao($this->chave('a.pdf'));

        self::assertNull($this->outbox->reivindicarProxima(900));
        self::assertSame('superado', $this->linha($envio)['estado']);
    }

    #[TestDox('envio novo DEPOIS de uma exclusão (arquivo recriado na mesma chave) sai normalmente')]
    public function testEnvioDepoisDeExclusao(): void
    {
        $this->outbox->registrarExclusao($this->chave('a.pdf'));
        $envio = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');

        self::assertSame($envio, $this->outbox->reivindicarProxima(900)?->id);
    }

    #[TestDox('mesma chave em processamento: a linha nova espera a posse da velha acabar; chave diferente sai')]
    public function testUmaChaveDeCadaVez(): void
    {
        $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('v1'), 2, 'application/pdf');
        $this->outbox->reivindicarProxima(900);
        $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('v2'), 2, 'application/pdf');
        $outra = $this->outbox->registrarEnvio($this->chave('b.pdf'), $this->sha('b'), 1, 'application/pdf');

        self::assertSame($outra, $this->outbox->reivindicarProxima(900)?->id, 'só a de outra chave pode sair agora');
        self::assertNull($this->outbox->reivindicarProxima(900));
    }

    #[TestDox('reagendar: volta a "pendente" com erro guardado e só sai depois da espera')]
    public function testReagendar(): void
    {
        $id = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->outbox->reagendar($this->outbox->reivindicarProxima(900) ?? self::fail('sem item'), 'espelho fora', 300);

        self::assertSame('pendente', $this->linha($id)['estado']);
        self::assertSame('espelho fora', $this->linha($id)['ultimo_erro']);
        self::assertNull($this->outbox->reivindicarProxima(900), 'antes da espera não sai');

        $this->relogio->sleep(301);
        self::assertSame($id, $this->outbox->reivindicarProxima(900)?->id);
    }

    #[TestDox('concluir só vale para linha em "processando" — uma marcação atrasada não reescreve o estado')]
    public function testConcluirSoEmProcessamento(): void
    {
        $id   = $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $item = $this->outbox->reivindicarProxima(900) ?? self::fail('sem item');
        $this->outbox->marcarVerificado($item);
        $this->outbox->marcarFalhou($item, 'tarde demais');

        self::assertSame('verificado', $this->linha($id)['estado']);
        self::assertNull($this->linha($id)['ultimo_erro']);
    }

    #[TestDox('transação do domínio desfeita leva a linha da outbox junto')]
    public function testTransacaoRevertidaLevaALinha(): void
    {
        $antes = $this->total();
        $this->conexao->beginTransaction();
        $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->conexao->rollBack();

        self::assertSame($antes, $this->total());
    }

    #[TestDox('transação do domínio confirmada mantém a linha')]
    public function testTransacaoConfirmadaMantemALinha(): void
    {
        $antes = $this->total();
        $this->conexao->beginTransaction();
        $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->conexao->commit();

        self::assertSame($antes + 1, $this->total());
    }

    #[TestDox('falha da outbox dentro da transação do domínio volta só o savepoint: o domínio segue e confirma')]
    public function testFalhaDaOutboxNaoQuebraATransacaoDoDominio(): void
    {
        $antes = $this->total();
        $this->conexao->beginTransaction();
        $this->outbox->registrarEnvio($this->chave('ok.pdf'), $this->sha('ok'), 1, 'application/pdf');

        try {
            // SHA com 65 caracteres estoura a coluna: erro do banco DENTRO da transação do domínio.
            $this->outbox->registrarEnvio($this->chave('ruim.pdf'), str_repeat('a', 65), 1, 'application/pdf');
            self::fail('a coluna devia recusar');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(1, (int) $this->conexao->fetchOne('SELECT 1'), 'a transação do domínio continua utilizável');
        $this->conexao->commit();
        self::assertSame($antes + 1, $this->total(), 'a linha boa ficou; a ruim, não');
    }

    #[TestDox('fuso do processo não desloca a posse: worker em São Paulo reivindica, worker em UTC no mesmo instante NÃO pega a mesma linha')]
    public function testFusoNaoDuplicaProcessamento(): void
    {
        $emSaoPaulo = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 09:00:00', 'America/Sao_Paulo'));
        $emUtc      = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 12:00:00', 'UTC'));
        $id         = $emUtc->registrarEnvio($this->chave('fuso-posse.pdf'), $this->sha('f'), 1, 'application/pdf');

        self::assertSame($id, $emSaoPaulo->reivindicarProxima(900)?->id);
        self::assertNull($emUtc->reivindicarProxima(900), 'sem UTC a posse pareceria vencida há 3 h e a linha sairia duas vezes');
    }

    #[TestDox('fuso do processo não prende a fila: linha registrada em UTC sai para um worker em São Paulo no mesmo instante')]
    public function testFusoNaoPrendeAFila(): void
    {
        $emUtc      = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 12:00:00', 'UTC'));
        $emSaoPaulo = new OutboxDeReplicacao($this->conexao, new MockClock('2026-10-15 09:00:00', 'America/Sao_Paulo'));
        $id         = $emUtc->registrarEnvio($this->chave('fuso-fila.pdf'), $this->sha('g'), 1, 'application/pdf');

        self::assertSame($id, $emSaoPaulo->reivindicarProxima(900)?->id, 'sem UTC a linha só sairia 3 h depois');
    }

    #[TestDox('contar por estado agrupa as linhas')]
    public function testContarPorEstado(): void
    {
        $this->outbox->registrarEnvio($this->chave('a.pdf'), $this->sha('a'), 1, 'application/pdf');
        $this->outbox->registrarExclusao($this->chave('b.pdf'));

        $contagem = $this->outbox->contarPorEstado();

        self::assertGreaterThanOrEqual(1, $contagem['pendente'] ?? 0);
        self::assertGreaterThanOrEqual(1, $contagem['registrado'] ?? 0);
        self::assertSame('registrado', $this->outbox->ultimoEstadoDe('core/t/1/uploads/pastas/b.pdf'));
        self::assertNull($this->outbox->ultimoEstadoDe('core/t/1/uploads/pastas/nunca.pdf'));
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(1), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    private function sha(string $conteudo): string
    {
        return hash('sha256', $conteudo);
    }

    /** @return array<string, mixed> */
    private function linha(int $id): array
    {
        return $this->conexao->fetchAssociative('SELECT * FROM armazenamento_replicacao WHERE id = ?', [$id]) ?: self::fail('linha #' . $id . ' não existe');
    }

    private function total(): int
    {
        return (int) $this->conexao->fetchOne('SELECT count(*) FROM armazenamento_replicacao');
    }
}
