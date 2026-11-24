<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Service\ChaveDoConvenio;
use App\Master\UseCase\AlterarConvenioAtivoUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Chave geral do Convênio OAB (pedido do Farlei M2.1, 24/11/2026): `convenio.ativo` em configuracao_global. */
#[CoversClass(ConfiguracaoGlobal::class)]
#[CoversClass(ChaveDoConvenio::class)]
#[CoversClass(AlterarConvenioAtivoUseCase::class)]
final class ChaveDoConvenioTest extends TestCase
{
    #[TestDox('sem linha ou sem a chave, o convênio vale ligado (como o sistema sempre foi)')]
    public function testPadraoLigado(): void
    {
        self::assertTrue($this->chave(null)->ativo());
        self::assertTrue($this->chave(new ConfiguracaoGlobal())->ativo());
        self::assertNull((new ConfiguracaoGlobal())->getConvenioAtivo());
    }

    #[TestDox('a chave mora fora do catálogo: a tela de configurações não a mostra nem a apaga')]
    public function testForaDoCatalogo(): void
    {
        $linha = new ConfiguracaoGlobal();
        $linha->definirConvenioAtivo(false, null, new \DateTimeImmutable());
        $linha->definirValores(['sessao' => '60'], null, new \DateTimeImmutable());

        self::assertSame(['sessao' => '60'], $linha->getValores());
        self::assertFalse($linha->getConvenioAtivo());
        self::assertFalse($this->chave($linha)->ativo());
    }

    #[TestDox('desligar e religar gravam a chave com quem e quando, numa linha nova ou na que já existe')]
    public function testAlterar(): void
    {
        $master = new User();
        $relogio = new MockClock('2026-11-24 10:00:00');
        $salvas = [];
        $repo = $this->createMock(ConfiguracaoGlobalRepository::class);
        $repo->method('buscarUnica')->willReturnOnConsecutiveCalls(null, $existente = new ConfiguracaoGlobal());
        $repo->expects(self::exactly(2))->method('salvar')->willReturnCallback(static function (ConfiguracaoGlobal $linha, bool $flush) use (&$salvas): void {
            self::assertTrue($flush);
            $salvas[] = $linha;
        });
        $sut = new AlterarConvenioAtivoUseCase($repo, new EscopoDeAuditoriaDePlataforma(), $relogio);

        self::assertFalse($sut->executar(false, $master));
        self::assertFalse($salvas[0]->getConvenioAtivo());
        self::assertSame($master, $salvas[0]->getAlteradoPor());
        self::assertSame('2026-11-24 10:00', $salvas[0]->getAlteradoEm()->format('Y-m-d H:i'));

        self::assertTrue($sut->executar(true, $master));
        self::assertSame($existente, $salvas[1]);
        self::assertTrue($existente->getConvenioAtivo());
    }

    private function chave(?ConfiguracaoGlobal $linha): ChaveDoConvenio
    {
        $repo = $this->createStub(ConfiguracaoGlobalRepository::class);
        $repo->method('atual')->willReturn($linha);

        return new ChaveDoConvenio($repo);
    }
}
