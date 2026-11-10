<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\EventListener\FalhaDoPdfFinalListener;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Tests\AssinaturaEletronica\Support\OficinaDoVinculo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * A falha definitiva do worker do PDF final (spec assinatura-documentos-etapa-3, E3-11): quando o Messenger desiste
 * de gerar ou gravar o PDF final, o vínculo pendente vira "vínculo não aplicado" com o motivo do PDF indisponível e se
 * desfaz (o "Vincular à pasta" volta). Enquanto houver nova tentativa, nada muda. O EntityManager fechado pela falha
 * é reaberto antes de ler o documento (provado em DocumentoParaAssinarRepositoryTest).
 */
#[CoversClass(FalhaDoPdfFinalListener::class)]
#[CoversClass(VinculoComAPasta::class)]
final class FalhaDoPdfFinalListenerTest extends TestCase
{
    use OficinaDoVinculo;

    protected function setUp(): void
    {
        $this->montarVinculo();
    }

    #[TestDox('o worker desistiu de gerar o PDF final: o vínculo não é aplicado, a trilha diz "PDF indisponível" e o vínculo se desfaz')]
    public function testFalhaDefinitiva(): void
    {
        $documento = $this->pendenteQueConcluiu();

        $this->ouvinte()($this->falha(new \RuntimeException('memória esgotada ao gerar')));

        $this->assertNaoAplicado($documento);
        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros, 'o erro de dentro do handler vai para o log');
        self::assertSame('memória esgotada ao gerar', $erros[0]['contexto']['erro'] ?? null);
    }

    #[TestDox('ainda vai tentar de novo: nada muda, o vínculo segue pendente')]
    public function testComNovaTentativa(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $antes = $this->tiposGravados();
        $evento = $this->falha(new \RuntimeException('armazenamento fora do ar'));
        $evento->setForRetry();

        $this->ouvinte()($evento);

        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(42, $documento->getPasta()?->getId());
        self::assertSame($antes, $this->tiposGravados());
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('outro processo guardou o PDF final durante a tentativa (o primeiro download): relido na trava, o vínculo vai para a pasta')]
    public function testPdfGuardadoDuranteATentativa(): void
    {
        $documento = $this->pendenteQueConcluiu();
        // A instância do worker é a de antes; a linha relida na trava já tem o PDF final.
        $this->aoTravar = function (DocumentoParaAssinar $d): void {
            if (ChavesDeAssinaturaEletronica::pdfFinal($d) === null) {
                $this->guardarPdfFinal($d);
            }
        };

        $this->ouvinte()($this->falha(new \RuntimeException('a gravação do worker falhou')));

        self::assertFalse($documento->isVinculoPendente());
        self::assertCount(1, $this->naPasta);
        self::assertSame(['vinculo_definido', 'vinculo_feito'], $this->tiposGravados());
        self::assertSame([], $this->logger->doNivel('error'), 'sem erro falso de PDF indisponível no log');
    }

    #[TestDox('PDF final guardado só na trava da falha: nada é desfeito, o vínculo segue pendente para o evento do PDF guardado')]
    public function testPdfGuardadoNaTravaDaFalha(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $antes = $this->tiposGravados();
        $travas = 0;
        $this->aoTravar = function (DocumentoParaAssinar $d) use (&$travas): void {
            if (++$travas === 2) {
                $this->guardarPdfFinal($d);
            }
        };

        $this->ouvinte()($this->falha(new \RuntimeException('a gravação do worker falhou')));

        self::assertSame(2, $travas, 'relido antes de decidir e de novo na trava da falha');
        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(42, $documento->getPasta()?->getId());
        self::assertSame($antes, $this->tiposGravados(), 'sem "vínculo não aplicado"');
        self::assertSame([], $this->naPasta);
    }

    #[TestDox('vínculo pendente de documento ainda aguardando: nada muda')]
    public function testAguardando(): void
    {
        $documento = $this->documentoEnviado();
        $this->pasta();
        $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        $antes = $this->tiposGravados();

        $this->ouvinte()($this->falha(new \RuntimeException('falhou')));

        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(42, $documento->getPasta()?->getId());
        self::assertSame($antes, $this->tiposGravados());
        self::assertSame([], $this->despachadas, 'aguardando: não pede o PDF ao worker');
    }

    #[TestDox('o EntityManager é reaberto antes de ler o escritório e o documento')]
    public function testReabreAntesDeLer(): void
    {
        $this->pendenteQueConcluiu();
        $ordem = [];
        $this->documentos->expects(self::once())->method('reabrirSeFechado')->willReturnCallback(static function () use (&$ordem): void {
            $ordem[] = 'reabrir';
        });
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturnCallback(function (mixed $id) use (&$ordem): ?Tenant {
            $ordem[] = 'ler';

            return $this->tenant;
        });

        (new FalhaDoPdfFinalListener($tenants, $this->documentos, $this->vinculo(), $this->logger))($this->falha(new \RuntimeException('falhou')));

        self::assertSame(['reabrir', 'ler'], $ordem);
    }

    #[TestDox('nada a fazer: outra mensagem, documento sem vínculo pendente, ou escritório que sumiu')]
    public function testNadaAFazer(): void
    {
        $outra = new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'async', new \RuntimeException('outra fila'));
        $this->ouvinte()($outra);

        $semVinculo = $this->concluido(comPdf: false);
        $antes = $this->tiposGravados();
        $this->ouvinte()($this->falha(new \RuntimeException('falhou')));
        self::assertFalse($semVinculo->isVinculoPendente());
        self::assertSame($antes, $this->tiposGravados());

        $semEscritorio = new GerarPdfFinalDoDocumento(1, 404);
        $this->ouvinte()(new WorkerMessageFailedEvent(new Envelope($semEscritorio), 'async', new \RuntimeException('falhou')));

        self::assertSame($antes, $this->tiposGravados());
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('erro inesperado no ouvinte: vai para o log e não sobe (a mensagem segue para a fila de falhas)')]
    public function testErroNoOuvinteNaoSobe(): void
    {
        $this->pendenteQueConcluiu();
        $this->documentos->method('reabrirSeFechado')->willThrowException(new \RuntimeException('banco fora do ar'));

        $this->ouvinte()($this->falha(new \RuntimeException('falhou')));

        $erros = $this->logger->doNivel('error');
        self::assertCount(1, $erros);
        self::assertSame('banco fora do ar', $erros[0]['contexto']['erro'] ?? null);
    }

    private function ouvinte(): FalhaDoPdfFinalListener
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => $id === 9 ? $this->tenant : null);

        return new FalhaDoPdfFinalListener($tenants, $this->documentos, $this->vinculo(), $this->logger);
    }

    /** O Messenger desistiu (sem `setForRetry()`) do PDF final do documento 1 do escritório 9, com o erro embrulhado. */
    private function falha(\Throwable $erro): WorkerMessageFailedEvent
    {
        $envelope = new Envelope(new GerarPdfFinalDoDocumento(1, 9, 2));

        return new WorkerMessageFailedEvent($envelope, 'async', new HandlerFailedException($envelope, [$erro]));
    }

    /** Vínculo definido com o documento aguardando; depois todos assinam, e o worker ainda não guardou o PDF final. */
    private function pendenteQueConcluiu(): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        $this->pasta();
        $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        foreach ($documento->getSignatarios() as $s) {
            $documento->assinar($s, $s->getNome(), self::CPF_ANA, 'rubrica.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:45:00'));
        }
        self::assertTrue($documento->isVinculoPendente());

        return $documento;
    }

    private function assertNaoAplicado(DocumentoParaAssinar $documento): void
    {
        self::assertFalse($documento->isVinculoPendente(), 'o vínculo pendente se desfaz');
        self::assertNull($documento->getPasta(), 'sem pasta: o "Vincular à pasta" volta');
        self::assertNull($documento->getVinculadoPor());
        self::assertSame([], $this->naPasta, 'nada vai para a pasta');
        $evento = $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado);
        self::assertSame(['nup' => 'P-0042', 'motivo' => 'assinatura_eletronica.vinculo_motivo.pdf_indisponivel', 'chave' => 1], $evento->getDados());
        self::assertSame(7, $evento->getUsuario()?->getId(), 'em nome de quem vinculou');
    }
}
