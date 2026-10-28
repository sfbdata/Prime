<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\ArmazenamentoEspelhado;
use App\Shared\EspelhoRemoto\CacheDeLeituraDoEspelho;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2;
use App\Shared\EspelhoRemoto\Replica\ReplicadorDeArquivos;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\LoggerEspiao;
use App\Tests\Shared\Doubles\ProvedorDoEspelhoFixo;
use App\Tests\Shared\Doubles\S3Simulado;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * O worker da réplica de ponta a ponta (spec P2 §14): decorador → outbox (PostgreSQL) → worker →
 * adaptador R2 de verdade → S3 simulado (sem rede). O disco local é o dublê em memória.
 */
#[CoversClass(ReplicadorDeArquivos::class)]
final class ReplicadorDeArquivosTest extends KernelTestCase
{
    private const NOME_PESSOAL = 'a1b2c3d4e5f60718293a4b5c6d7e8f90. RG - Antônio Carlos';
    private const SEGREDO = 'SEGREDO-QUE-NUNCA-PODE-VAZAR';

    private Connection $conexao;
    private MockClock $relogio;
    private ArmazenamentoEmMemoria $local;
    private OutboxDeReplicacao $outbox;
    private ArmazenamentoEspelhado $espelhado;
    private S3Simulado $s3;
    private EspelhoRemotoR2 $espelho;
    private LoggerEspiao $logger;
    private ReplicadorDeArquivos $replicador;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao    = static::getContainer()->get(Connection::class);
        $this->conexao->executeStatement('DELETE FROM armazenamento_replicacao');
        $this->relogio    = new MockClock('2026-10-15 12:00:00');
        $this->local      = new ArmazenamentoEmMemoria();
        $this->outbox     = new OutboxDeReplicacao($this->conexao, $this->relogio);
        $this->logger     = new LoggerEspiao();
        $this->espelhado  = new ArmazenamentoEspelhado($this->local, $this->outbox, new PoliticaDeEspelhamento('pasta_documento'), $this->logger, ProvedorDoEspelhoFixo::naoConfigurado(), new CacheDeLeituraDoEspelho(sys_get_temp_dir() . '/jusprime-cache-nao-usado', 0));
        $this->s3         = new S3Simulado();
        $this->espelho    = new EspelhoRemotoR2(ClienteR2::criar($this->s3->endpoint, $this->s3->chaveDeAcesso, self::SEGREDO, $this->s3->cliente()), $this->s3->bucket);
        $this->replicador = new ReplicadorDeArquivos($this->outbox, $this->local, $this->logger, new PoliticaDeEspelhamento('pasta_documento'));
    }

    #[TestDox('gravou no disco → o worker envia, o objeto fica igual no espelho e a linha vira "verificado"')]
    public function testEnviaOPendente(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->enviados);
        self::assertSame('conteúdo', $this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
        self::assertSame(['verificado' => 1], $this->outbox->contarPorEstado());
    }

    #[TestDox('idempotência: objeto já igual no espelho → "verificado" sem PUT (reprocessar depois de queda não reenvia)')]
    public function testJaEstaNoEspelhoNaoReenvia(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->s3->semear(LayoutDeChaveRemota::chaveDe($chave), 'conteúdo', ['sha256' => hash('sha256', 'conteúdo')]);

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->jaEstavamNoEspelho);
        self::assertSame(0, $this->puts());
    }

    #[TestDox('duplicidade: o mesmo arquivo registrado duas vezes sai UMA vez; a linha velha é superada')]
    public function testDuplicidade(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->outbox->registrarEnvio($chave, hash('sha256', 'conteúdo'), \strlen('conteúdo'), 'application/pdf');

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->enviados);
        self::assertSame(1, $this->puts());
        self::assertSame(['superado' => 1, 'verificado' => 1], $this->outbox->contarPorEstado());
    }

    #[TestDox('sobrescrita: v1 e v2 na fila → só v2 sai, e é v2 que fica no espelho')]
    public function testSobrescritaSoAMaisNovaSai(): void
    {
        $chave = $this->gravar('a.pdf', 'versão 1');
        $this->espelhado->gravar($chave, FonteDeConteudo::deTexto('versão 2'));

        $this->replicador->processar($this->espelho, 10);

        self::assertSame('versão 2', $this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
        self::assertSame(1, $this->puts());
    }

    #[TestDox('sobrescrita DURANTE o processamento: o item velho vê o disco mudado e não envia; o novo envia v2')]
    public function testSobrescritaDuranteOProcessamento(): void
    {
        $chave = $this->gravar('a.pdf', 'versão 1');
        $velho = $this->outbox->reivindicarProxima(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS, ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS) ?? self::fail('sem item');

        // Enquanto o worker A segura o v1, o usuário regrava: v2 entra na fila e no disco.
        $this->espelhado->gravar($chave, FonteDeConteudo::deTexto('versão 2'));
        $this->conexao->executeStatement('UPDATE armazenamento_replicacao SET estado = :p, processando_ate = NULL WHERE id = :id', ['p' => 'pendente', 'id' => $velho->id]);

        $this->replicador->processar($this->espelho, 10);

        self::assertSame('versão 2', $this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
        self::assertSame(1, $this->puts(), 'o v1 nunca sai');
    }

    #[TestDox('disco mudou SEM registro mais novo (outbox falhou, política desligada, transação desfeita): o velho vira "superado" e um envio com o conteúdo atual é registrado — a fila converge')]
    public function testDiscoMudouSemRegistroNovoReRegistra(): void
    {
        $chave = $this->gravar('a.pdf', 'versão 1');
        $this->local->gravar($chave, FonteDeConteudo::deTexto('versão 2 sem passar pelo decorador'));

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->superados);
        self::assertSame(1, $resumo->enviados, 'o envio re-registrado sai na mesma rodada');
        self::assertSame('versão 2 sem passar pelo decorador', $this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
        self::assertSame(1, $this->puts(), 'a versão 1 nunca sai');
    }

    #[TestDox('posse perdida antes do PUT (outro worker pegou a chave enquanto este consultava): este NÃO envia — o envio velho não cai por cima do novo')]
    public function testPossePerdidaAntesDoPutNaoEnvia(): void
    {
        $chave   = $this->gravar('corrida.pdf', 'versão 1');
        $outro   = new OutboxDeReplicacao($this->conexao, $this->relogio);
        $relogio = $this->relogio;
        $espiao  = new class ($this->espelho, $relogio, $outro) implements \App\Shared\EspelhoRemoto\EspelhoRemoto {
            public int $envios = 0;
            private bool $jaRoubou = false;

            public function __construct(private readonly \App\Shared\EspelhoRemoto\EspelhoRemoto $real, private readonly MockClock $relogio, private readonly OutboxDeReplicacao $outro)
            {
            }

            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
                $this->envios++;
                $this->real->enviar($chave, $conteudo, $tamanhoBytes, $sha256, $mimeType);
            }

            public function consultar(ChaveDeArquivo $chave): ?\App\Shared\EspelhoRemoto\MetadadosRemotos
            {
                if (!$this->jaRoubou) {
                    // Este worker "travou" além da posse; outro worker pega a mesma linha.
                    $this->jaRoubou = true;
                    $this->relogio->sleep(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS + 1);
                    $this->outro->reivindicarProxima(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS, ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS);
                }

                return $this->real->consultar($chave);
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return $this->consultar($chave) !== null;
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                $this->real->baixar($chave, $destino, $tamanhoEsperado, $sha256Esperado);
            }
        };

        $resumo = $this->replicador->processar($espiao, 1);

        self::assertSame(1, $resumo->possesPerdidas);
        self::assertSame(0, $espiao->envios, 'sem posse renovada, nada sai');
        self::assertSame('processando', $this->conexao->fetchOne('SELECT estado FROM armazenamento_replicacao'), 'a linha é do outro worker agora');
        self::assertNull($this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
    }

    #[TestDox('erro inesperado (TypeError, erro do banco) é transitório: reagenda com backoff, não derruba a rodada')]
    public function testErroInesperadoEhTransitorio(): void
    {
        $this->gravar('b.pdf', 'x');
        $this->gravar('c.pdf', 'y');
        $quebrado = new class () implements \App\Shared\EspelhoRemoto\EspelhoRemoto {
            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
            }

            public function consultar(ChaveDeArquivo $chave): ?\App\Shared\EspelhoRemoto\MetadadosRemotos
            {
                throw new \TypeError('bug simulado');
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return false;
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                throw new \LogicException('a réplica e a reconciliação não baixam conteúdo');
            }
        };

        $resumo = $this->replicador->processar($quebrado, 10);

        self::assertSame(2, $resumo->reivindicados, 'a rodada seguiu para o segundo item');
        self::assertSame(2, $resumo->reagendados);
        self::assertSame(['pendente' => 2], $this->outbox->contarPorEstado());
    }

    #[TestDox('a posse é maior que a duração máxima de uma requisição ao R2, com folga para reler o arquivo')]
    public function testPosseMaiorQueADuracaoDoHttp(): void
    {
        self::assertGreaterThanOrEqual(ClienteR2::DURACAO_MAXIMA_DA_REQUISICAO + 120, ReplicadorDeArquivos::POSSE_EM_SEGUNDOS);
    }

    #[TestDox('excluído depois do envio pendente: nada é enviado e nada é apagado — o excluído não volta')]
    public function testExcluidoNaoVolta(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->espelhado->excluir($chave);

        $this->replicador->processar($this->espelho, 10);

        self::assertSame([], $this->s3->chavesGravadas());
        self::assertNotContains('DELETE', $this->s3->metodosUsados());
        self::assertSame(['registrado' => 1, 'superado' => 1], $this->outbox->contarPorEstado());
    }

    #[TestDox('arquivo local sumiu sem evento (fora do decorador) → "descartado", sem envio')]
    public function testLocalSumiu(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->local->excluir($chave);

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->descartados);
        self::assertSame([], $this->s3->requisicoes());
    }

    #[TestDox('falha do R2: reagenda com backoff crescente e, esgotadas as tentativas, marca "falhou"')]
    public function testFalhaDoR2ComBackoffAteEsgotar(): void
    {
        $this->gravar('a.pdf', 'conteúdo');
        $esperas = [];

        for ($tentativa = 1; $tentativa <= ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS; $tentativa++) {
            $this->s3->falharAProxima(new MockResponse('<?xml version="1.0"?><Error><Code>InternalError</Code></Error>', ['http_code' => 500]));
            $resumo = $this->replicador->processar($this->espelho, 1);
            $linha  = $this->conexao->fetchAssociative('SELECT * FROM armazenamento_replicacao ORDER BY id DESC LIMIT 1') ?: self::fail('sem linha');

            if ($tentativa < ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS) {
                self::assertSame(1, $resumo->reagendados, 'tentativa ' . $tentativa);
                self::assertSame('pendente', $linha['estado']);
                $esperas[] = (new \DateTimeImmutable((string) $linha['proxima_tentativa_em'], new \DateTimeZone('UTC')))->getTimestamp() - $this->relogio->now()->getTimestamp();
                $this->relogio->sleep(50_000);
            } else {
                self::assertSame(1, $resumo->falharam);
                self::assertSame('falhou', $linha['estado']);
                self::assertStringContainsString('tentativas esgotadas', (string) $linha['ultimo_erro']);
            }
        }

        self::assertSame([60, 300, 1800, 7200, 43200], $esperas);
    }

    #[TestDox('queda do worker: item reivindicado e abandonado volta depois da posse e é enviado na tentativa seguinte')]
    public function testQuedaDoWorker(): void
    {
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->outbox->reivindicarProxima(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS, ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS);

        self::assertSame(0, $this->replicador->processar($this->espelho, 10)->reivindicados, 'com a posse do morto ainda válida, ninguém pega');

        $this->relogio->sleep(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS + 1);
        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(1, $resumo->enviados);
        self::assertSame('conteúdo', $this->s3->conteudoDe(LayoutDeChaveRemota::chaveDe($chave)));
        self::assertSame(2, (int) $this->conexao->fetchOne('SELECT tentativas FROM armazenamento_replicacao'));
    }

    #[TestDox('linha que não vira chave é falha permanente, sem tentar de novo')]
    public function testLinhaInvalidaFalhaNaHora(): void
    {
        // Categoria ligada, nome que não vira chave (caminho com "..").
        $this->conexao->executeStatement(
            "INSERT INTO armazenamento_replicacao (operacao, escopo_tenant_id, categoria, nome, chave_remota, sha256, tamanho_bytes, mime_type, estado, tentativas, proxima_tentativa_em, criado_em, atualizado_em)
             VALUES ('enviar', 1, 'pasta_documento', '../x.pdf', 'core/t/1/uploads/x/x.pdf', :sha, 1, 'application/pdf', 'pendente', 0, '2026-10-15 12:00:00', '2026-10-15 12:00:00', '2026-10-15 12:00:00')",
            ['sha' => hash('sha256', 'x')],
        );

        $this->replicador->processar($this->espelho, 10);

        self::assertSame(['falhou' => 1], $this->outbox->contarPorEstado());
        self::assertSame([], $this->s3->requisicoes());
    }

    #[TestDox('linha de categoria que não existe nunca é reivindicada pelo worker (não é categoria ligada); a fila sem filtro a marca falhou')]
    public function testCategoriaDesconhecidaNaoSaiPeloWorker(): void
    {
        $this->conexao->executeStatement(
            "INSERT INTO armazenamento_replicacao (operacao, escopo_tenant_id, categoria, nome, chave_remota, sha256, tamanho_bytes, mime_type, estado, tentativas, proxima_tentativa_em, criado_em, atualizado_em)
             VALUES ('enviar', 1, 'categoria_que_nao_existe', 'x.pdf', 'core/t/1/uploads/x/x.pdf', :sha, 1, 'application/pdf', 'pendente', 0, '2026-10-15 12:00:00', '2026-10-15 12:00:00', '2026-10-15 12:00:00')",
            ['sha' => hash('sha256', 'x')],
        );

        $this->replicador->processar($this->espelho, 10);
        self::assertSame(['pendente' => 1], $this->outbox->contarPorEstado());

        self::assertNull($this->outbox->reivindicarProxima(ReplicadorDeArquivos::POSSE_EM_SEGUNDOS, ReplicadorDeArquivos::MAXIMO_DE_TENTATIVAS));
        self::assertSame(['falhou' => 1], $this->outbox->contarPorEstado());
        self::assertSame([], $this->s3->requisicoes());
    }

    #[TestDox('o erro guardado na outbox e o log não levam nome do arquivo, chave remota nem credencial')]
    public function testErroSemDadosPessoais(): void
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, self::NOME_PESSOAL);
        $this->espelhado->gravar($chave, FonteDeConteudo::deTexto('x'));
        $this->s3->falharAProxima(new MockResponse('<?xml version="1.0"?><Error><Code>AccessDenied</Code></Error>', ['http_code' => 403]));

        $this->replicador->processar($this->espelho, 1);

        $erro = (string) $this->conexao->fetchOne('SELECT ultimo_erro FROM armazenamento_replicacao');
        self::assertStringContainsString('HTTP 403', $erro);
        self::assertStringContainsString('<chave #', $erro);
        foreach (array_merge([$erro], array_column($this->logger->registros, 'mensagem')) as $texto) {
            self::assertStringNotContainsString('Antônio', $texto);
            self::assertStringNotContainsString('core/t/7/uploads', $texto);
            self::assertStringNotContainsString(self::SEGREDO, $texto);
            self::assertStringNotContainsString($this->s3->chaveDeAcesso, $texto);
        }
    }

    #[TestDox('transação desfeita: sem linha na outbox, nada sai — o arquivo fica só no disco, como órfão local')]
    public function testTransacaoDesfeitaNaoReplica(): void
    {
        $this->conexao->beginTransaction();
        $chave = $this->gravar('a.pdf', 'conteúdo');
        $this->conexao->rollBack();

        $resumo = $this->replicador->processar($this->espelho, 10);

        self::assertSame(0, $resumo->reivindicados);
        self::assertTrue($this->local->existe($chave), 'o disco não é transacional: o arquivo ficou');
        self::assertSame([], $this->s3->requisicoes());
    }

    #[TestDox('limite da rodada é respeitado e o resto fica para a próxima')]
    public function testLimiteDaRodada(): void
    {
        foreach (['a', 'b', 'c'] as $nome) {
            $this->gravar($nome . '.pdf', $nome);
        }

        self::assertSame(2, $this->replicador->processar($this->espelho, 2)->enviados);
        self::assertSame(1, $this->replicador->processar($this->espelho, 2)->enviados);
    }

    private function gravar(string $nome, string $conteudo): ChaveDeArquivo
    {
        $chave = new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);

        return $this->espelhado->gravar($chave, FonteDeConteudo::deTexto($conteudo))->chave;
    }

    private function puts(): int
    {
        return \count(array_filter($this->s3->requisicoes(), static fn (array $r): bool => $r['metodo'] === 'PUT'));
    }
}
