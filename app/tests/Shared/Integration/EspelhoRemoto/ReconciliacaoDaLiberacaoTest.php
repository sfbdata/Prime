<?php

declare(strict_types=1);

namespace App\Tests\Shared\Integration\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\LayoutDeChaveRemota;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use App\Shared\EspelhoRemoto\MetadadosRemotos;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\Reconciliacao\AlertaDaReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\ClasseDeReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\FonteDeReferencias;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Reconciliacao\ReferenciaDeArquivo;
use App\Shared\EspelhoRemoto\Reconciliacao\RelatorioDeReconciliacao;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\EspelhoRemotoEmMemoria;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A reconciliação com o registro de liberação (spec `leitura-pelo-r2-e-liberacao-de-disco.md` D5, §4):
 * `liberado` (ausente no disco com linha aberta e o R2 conferindo) e `restaurado_sem_registro`
 * (presente no disco com linha aberta). As classes de sempre não mudam sem linha aberta.
 */
#[CoversClass(ReconciliadorDoEspelho::class)]
#[CoversClass(RelatorioDeReconciliacao::class)]
final class ReconciliacaoDaLiberacaoTest extends KernelTestCase
{
    private const CONTEUDO = 'conteúdo provado no R2';

    /** Faz o papel da resposta de erro 401 do Drive: o reconciliador recebe o SHA dele como suspeito. */
    private const ERRO_DO_DRIVE = 'erro-401-do-drive';

    private ArmazenamentoEmMemoria $local;
    private EspelhoRemotoEmMemoria $espelho;
    private OutboxDeReplicacao $outbox;
    private RegistroDeLiberacao $liberacoes;

    protected function setUp(): void
    {
        self::bootKernel();
        $conexao          = static::getContainer()->get(Connection::class);
        $relogio          = new MockClock('2026-11-03 12:00:00', 'UTC');
        $this->local      = new ArmazenamentoEmMemoria();
        $this->espelho    = new EspelhoRemotoEmMemoria();
        $this->outbox     = new OutboxDeReplicacao($conexao, $relogio);
        $this->liberacoes = new RegistroDeLiberacao($conexao, $relogio);
    }

    #[TestDox('ausente no disco, com linha aberta e o R2 com o mesmo tamanho e SHA: "liberado", que não alerta e fica fora das divergências')]
    public function testLiberado(): void
    {
        $ref = $this->liberada('liberado.pdf');
        $this->espelho->semear($this->chave('liberado.pdf'), self::CONTEUDO);

        $relatorio = $this->reconciliar([$ref]);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::LIBERADO));
        self::assertSame([], $relatorio->divergencias(), 'liberado conta à parte');
        self::assertSame(['pasta_documento' => 1], $relatorio->porCategoria(ClasseDeReconciliacao::LIBERADO));
        self::assertFalse(AlertaDaReconciliacao::avaliar($relatorio, [])->disparou());
    }

    #[TestDox('liberado e o R2 sem o objeto, com outro tamanho ou com outro SHA: a classe de sempre, que alerta (o R2 é a única cópia)')]
    public function testLiberadoComOR2Divergente(): void
    {
        $semObjeto = $this->liberada('sem-objeto.pdf');
        $tamanho   = $this->liberada('tamanho.pdf');
        $this->espelho->semear($this->chave('tamanho.pdf'), self::CONTEUDO . ' a mais');
        $sha = $this->liberada('sha.pdf');
        $this->espelho->semear($this->chave('sha.pdf'), self::CONTEUDO, hash('sha256', 'outro'));

        $relatorio = $this->reconciliar([$semObjeto, $tamanho, $sha]);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::AUSENTE_NO_ESPELHO));
        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::TAMANHO_DIVERGENTE));
        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::SHA_DIVERGENTE));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::LIBERADO));
        self::assertTrue(AlertaDaReconciliacao::avaliar($relatorio, [])->disparou());
    }

    #[TestDox('liberado e o R2 sem SHA no metadado: "sem_sha_no_espelho", como no disco, nunca "liberado"')]
    public function testLiberadoSemShaNoR2(): void
    {
        $ref = $this->liberada('sem-sha.pdf');
        // O dublê em memória sempre tem SHA: este espelho só responde a consulta, sem SHA no metadado.
        $semSha = new class (\strlen(self::CONTEUDO)) implements EspelhoRemoto {
            public function __construct(private readonly int $tamanho)
            {
            }

            public function consultar(ChaveDeArquivo $chave): MetadadosRemotos
            {
                return new MetadadosRemotos(LayoutDeChaveRemota::chaveDe($chave), $this->tamanho, null, 'application/pdf', null);
            }

            public function enviar(ChaveDeArquivo $chave, mixed $conteudo, int $tamanhoBytes, string $sha256, string $mimeType): void
            {
                throw new \LogicException('a reconciliação não envia');
            }

            public function existe(ChaveDeArquivo $chave): bool
            {
                return true;
            }

            public function baixar(ChaveDeArquivo $chave, mixed $destino, int $tamanhoEsperado, string $sha256Esperado): void
            {
                throw new \LogicException('a reconciliação não baixa');
            }

            public function excluir(ChaveDeArquivo $chave): void
            {
                throw new \LogicException('a reconciliação não apaga');
            }
        };

        $relatorio = $this->reconciliar([$ref], $semSha);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::SEM_SHA_NO_ESPELHO));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::LIBERADO));
    }

    #[TestDox('de volta ao disco com o conteúdo do erro 401 do Drive e a linha aberta: o suspeito vale mais que "restaurado_sem_registro"')]
    public function testSuspeitoPrevaleceSobreARestauracao(): void
    {
        $ref = $this->liberada('drive.pdf');
        $this->local->gravar($this->chave('drive.pdf'), FonteDeConteudo::deTexto(self::ERRO_DO_DRIVE));

        $relatorio = $this->reconciliar([$ref]);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::SUSPEITO_ERRO_DO_DRIVE));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::RESTAURADO_SEM_REGISTRO));
    }

    #[TestDox('presente no disco E com linha aberta: "restaurado_sem_registro", que não faz a rodada falhar')]
    public function testRestauradoSemRegistro(): void
    {
        $ref = $this->liberada('voltou.pdf');
        $this->local->gravar($this->chave('voltou.pdf'), FonteDeConteudo::deTexto(self::CONTEUDO));
        $this->espelho->semear($this->chave('voltou.pdf'), self::CONTEUDO);

        $relatorio = $this->reconciliar([$ref]);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::RESTAURADO_SEM_REGISTRO));
        self::assertSame([['fonte' => 'pasta_documento', 'id' => $ref->idDaLinha, 'classe' => 'restaurado_sem_registro']], $relatorio->divergencias());
        self::assertFalse(AlertaDaReconciliacao::avaliar($relatorio, [])->disparou());
    }

    #[TestDox('presente no disco com linha aberta, mas divergente do R2: a divergência que exige ação vale mais e não some')]
    public function testRestauradoDivergenteContinuaAlertando(): void
    {
        $ref = $this->liberada('voltou-outro.pdf');
        $this->local->gravar($this->chave('voltou-outro.pdf'), FonteDeConteudo::deTexto('versão nova do disco'));

        $relatorio = $this->reconciliar([$ref]);

        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::AUSENTE_NO_ESPELHO));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::RESTAURADO_SEM_REGISTRO));
    }

    #[TestDox('sem linha aberta nada muda: ausente no disco é local_ausente, presente e igual é igual; linha fechada também não conta')]
    public function testSemLinhaAbertaNadaMuda(): void
    {
        $ausente = new ReferenciaDeArquivo('pasta_documento', 1, $this->chave('ausente.pdf'));
        $this->local->gravar($this->chave('igual.pdf'), FonteDeConteudo::deTexto(self::CONTEUDO));
        $this->espelho->semear($this->chave('igual.pdf'), self::CONTEUDO);
        $igual   = new ReferenciaDeArquivo('pasta_documento', 2, $this->chave('igual.pdf'));
        $fechada = $this->liberada('fechada.pdf');
        $this->liberacoes->fechar(LayoutDeChaveRemota::chaveDe($this->chave('fechada.pdf')));

        $relatorio = $this->reconciliar([$ausente, $igual, $fechada]);

        self::assertSame(2, $relatorio->quantos(ClasseDeReconciliacao::LOCAL_AUSENTE));
        self::assertSame(1, $relatorio->quantos(ClasseDeReconciliacao::IGUAL));
        self::assertSame(0, $relatorio->quantos(ClasseDeReconciliacao::LIBERADO));
    }

    #[TestDox('a linha aberta é da chave do escritório: a mesma chave em outro escritório continua local_ausente')]
    public function testOutroEscritorio(): void
    {
        $this->liberada('mesmo.pdf');
        $this->espelho->semear($this->chave('mesmo.pdf'), self::CONTEUDO);
        $deOutro = new ReferenciaDeArquivo('pasta_documento', 9, new ChaveDeArquivo(EscopoDeArquivo::deTenant(8), CategoriaDeArquivo::PASTA_DOCUMENTO, 'mesmo.pdf'));

        self::assertSame(1, $this->reconciliar([$deOutro])->quantos(ClasseDeReconciliacao::LOCAL_AUSENTE));
    }

    private function liberada(string $nome): ReferenciaDeArquivo
    {
        $chave = $this->chave($nome);
        $this->liberacoes->registrar('lote-teste', $chave, hash('sha256', self::CONTEUDO), \strlen(self::CONTEUDO));

        return new ReferenciaDeArquivo('pasta_documento', random_int(1, 1_000_000), $chave);
    }

    private function chave(string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
    }

    /** @param list<ReferenciaDeArquivo> $referencias */
    private function reconciliar(array $referencias, ?EspelhoRemoto $espelho = null): RelatorioDeReconciliacao
    {
        $fonte = new class ($referencias) implements FonteDeReferencias {
            /** @param list<ReferenciaDeArquivo> $referencias */
            public function __construct(private readonly array $referencias)
            {
            }

            public function referencias(): iterable
            {
                return $this->referencias;
            }
        };

        return (new ReconciliadorDoEspelho($fonte, $this->local, $this->outbox, $this->liberacoes, hash('sha256', self::ERRO_DO_DRIVE)))->reconciliar($espelho ?? $this->espelho);
    }
}
