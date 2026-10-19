<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PainelDoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\MontarPainelDoPushUseCase;
use App\Processo\DTO\MovimentacaoParaPrevisao;
use App\Processo\Entity\Processo;
use App\Processo\Repository\PrevisaoRegistradaRepository;
use App\Processo\Service\PrevisaoProcessual;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Montagem do cartão da coluna direita do Push: estados do monitoramento e a taxa de acertos
 * (dc L.5105-5109), com os repositórios dublados. A consulta em si se prova no functional.
 */
#[CoversClass(MontarPainelDoPushUseCase::class)]
#[CoversClass(PainelDoPushOutput::class)]
final class MontarPainelDoPushUseCaseTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private PublicacaoDjenRepository&MockObject $publicacoes;
    private PrevisaoRegistradaRepository&MockObject $previsoes;
    private MontarPainelDoPushUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $this->previsoes = $this->createMock(PrevisaoRegistradaRepository::class);
        $this->tenant = new Tenant();
        $this->sut = new MontarPainelDoPushUseCase(
            $this->publicacoes,
            $this->previsoes,
            new PrevisaoProcessual(new DiasUteisNacionais(new CalendarioDoSistema())),
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );
    }

    private function pastaComProcesso(?Processo &$processo = null): Pasta
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso('0701134-57.2025.8.07.0007');
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');

        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $pasta->vincularProcesso($processo);

        return $pasta;
    }

    private function comPublicacoes(MovimentacaoParaPrevisao ...$movs): void
    {
        $this->publicacoes->method('listarParaPrevisaoDoTenant')->willReturn($movs);
    }

    private static function mov(string $data, string $tipo, string $texto): MovimentacaoParaPrevisao
    {
        return MovimentacaoParaPrevisao::doTeorHtml(new \DateTimeImmutable($data), $tipo, $texto);
    }

    #[TestDox('Sem processo: "Sem monitoramento" / "Vincule um processo", sem situação e sem tocar no banco')]
    public function testSemProcesso(): void
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        $this->publicacoes->expects(self::never())->method('listarParaPrevisaoDoTenant');

        $painel = $this->sut->executar($pasta, $this->tenant);

        self::assertFalse($painel->temProcesso);
        self::assertFalse($painel->ativo);
        self::assertSame('Sem monitoramento', $painel->statusTxt);
        self::assertSame('Vincule um processo', $painel->statusSub);
        self::assertSame('Sem processo vinculado', $painel->processo);
        self::assertNull($painel->situacao);
    }

    #[TestDox('Ativo: textos do desenho, "nº · tribunal" e "ainda não verificado"; sem publicação, sem situação')]
    public function testAtivoSemPublicacao(): void
    {
        $this->comPublicacoes();
        $this->previsoes->expects(self::never())->method('registrar');

        $painel = $this->sut->executar($this->pastaComProcesso(), $this->tenant);

        self::assertTrue($painel->ativo);
        self::assertSame('Monitoramento ativo', $painel->statusTxt);
        self::assertSame('Captura automática nos diários e no PJe', $painel->statusSub);
        self::assertSame('0701134-57.2025.8.07.0007 · TJDFT', $painel->processo);
        self::assertSame('Última verificação: ainda não verificado', $painel->ultima);
        self::assertNull($painel->situacao);
    }

    #[TestDox('Última verificação: "hoje, HH:MM", "ontem, HH:MM" e "dd/mm/aaaa, HH:MM"')]
    public function testUltimaVerificacao(): void
    {
        $this->comPublicacoes();
        $pasta = $this->pastaComProcesso($processo);

        $processo->registrarVerificacao(new \DateTimeImmutable('2026-10-19 09:12'));
        self::assertSame('Última verificação: hoje, 09:12', $this->sut->executar($pasta, $this->tenant)->ultima);

        $processo->registrarVerificacao(new \DateTimeImmutable('2026-10-18 18:40'));
        self::assertSame('Última verificação: ontem, 18:40', $this->sut->executar($pasta, $this->tenant)->ultima);

        $processo->registrarVerificacao(new \DateTimeImmutable('2026-10-05 08:00'));
        self::assertSame('Última verificação: 05/10/2026, 08:00', $this->sut->executar($pasta, $this->tenant)->ultima);
    }

    #[TestDox('Primeira previsão do processo: grava a chave e o previsto; rodapé "Ainda sem previsão anterior para conferir"')]
    public function testPrimeiraPrevisao(): void
    {
        $this->comPublicacoes(self::mov('2026-10-14', 'Intimação', 'Sentença publicada.'));
        $this->previsoes->method('ultimaDoProcesso')->willReturn(null);
        $this->previsoes->method('contarConferidas')->willReturn(['conferidas' => 0, 'acertos' => 0]);
        $this->previsoes->expects(self::never())->method('conferir');
        $this->previsoes->expects(self::once())->method('registrar')
            ->with($this->tenant, self::isInstanceOf(Processo::class), '14/10/2026 Intimação', 'Apelação ou embargos de declaração', new \DateTimeImmutable(self::AGORA));

        $painel = $this->sut->executar($this->pastaComProcesso(), $this->tenant);

        self::assertNotNull($painel->situacao);
        self::assertSame('Prazo recursal', $painel->situacao->situacao);
        self::assertSame('Ainda sem previsão anterior para conferir', $painel->situacao->aprendizado);
    }

    #[TestDox('Mesma última movimentação (recarregar a aba): não confere nem grava de novo')]
    public function testMesmaChaveEhIdempotente(): void
    {
        $this->comPublicacoes(self::mov('2026-10-14', 'Intimação', 'Sentença publicada.'));
        $this->previsoes->method('ultimaDoProcesso')
            ->willReturn(['id' => 7, 'chave' => '14/10/2026 Intimação', 'previsto' => 'Apelação ou embargos de declaração', 'conferida' => false]);
        $this->previsoes->method('contarConferidas')->willReturn(['conferidas' => 2, 'acertos' => 1]);
        $this->previsoes->expects(self::never())->method('conferir');
        $this->previsoes->expects(self::never())->method('registrar');

        $painel = $this->sut->executar($this->pastaComProcesso(), $this->tenant);

        self::assertSame('Previsões conferidas: 1 de 2 acertaram', $painel->situacao?->aprendizado);
    }

    #[TestDox('A última movimentação mudou: confere a previsão anterior contra o que aconteceu e grava a nova')]
    public function testMovimentacaoNovaConfereAAnterior(): void
    {
        $this->comPublicacoes(
            self::mov('2026-10-14', 'Intimação', 'Apelação interposta pela parte ré.'),
            self::mov('2026-09-01', 'Intimação', 'Sentença publicada.'),
        );
        $this->previsoes->method('ultimaDoProcesso')
            ->willReturn(['id' => 7, 'chave' => '01/09/2026 Intimação', 'previsto' => 'Apelação ou embargos de declaração', 'conferida' => false]);
        $this->previsoes->method('contarConferidas')->willReturn(['conferidas' => 1, 'acertos' => 1]);
        $this->previsoes->expects(self::once())->method('conferir')->with(7, $this->tenant, true);
        $this->previsoes->expects(self::once())->method('registrar')
            ->with($this->tenant, self::isInstanceOf(Processo::class), '14/10/2026 Intimação', 'Contrarrazões', self::anything());

        $painel = $this->sut->executar($this->pastaComProcesso(), $this->tenant);

        self::assertSame('Previsões conferidas: 1 de 1 acertaram', $painel->situacao?->aprendizado);
    }

    #[TestDox('Previsão anterior sem regra (previsto vazio) ou já conferida: não confere, só grava a nova')]
    public function testAnteriorVaziaNaoConfere(): void
    {
        $this->comPublicacoes(self::mov('2026-10-14', 'Intimação', 'Sentença publicada.'));
        $this->previsoes->method('ultimaDoProcesso')
            ->willReturn(['id' => 7, 'chave' => '01/09/2026 Edital', 'previsto' => '', 'conferida' => false]);
        $this->previsoes->method('contarConferidas')->willReturn(['conferidas' => 0, 'acertos' => 0]);
        $this->previsoes->expects(self::never())->method('conferir');
        $this->previsoes->expects(self::once())->method('registrar');

        $this->sut->executar($this->pastaComProcesso(), $this->tenant);
    }

    #[TestDox('Pausado: textos do desenho, a situação vem (a tela a esconde) e nada é gravado')]
    public function testPausadoNaoGrava(): void
    {
        $this->comPublicacoes(self::mov('2026-10-14', 'Intimação', 'Sentença publicada.'));
        $pasta = $this->pastaComProcesso($processo);
        $processo->pausarMonitoramento(new User(), new \DateTimeImmutable('2026-10-18'));
        $this->previsoes->method('contarConferidas')->willReturn(['conferidas' => 0, 'acertos' => 0]);
        $this->previsoes->expects(self::never())->method('ultimaDoProcesso');
        $this->previsoes->expects(self::never())->method('registrar');

        $painel = $this->sut->executar($pasta, $this->tenant);

        self::assertFalse($painel->ativo);
        self::assertSame('Monitoramento pausado', $painel->statusTxt);
        self::assertSame('Novas movimentações não serão capturadas', $painel->statusSub);
        self::assertNotNull($painel->situacao);
    }
}
