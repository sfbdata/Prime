<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Tenant\Tenant;
use App\Master\DTO\ConvenioVigente;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioDaUf;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Repository\ConfiguracaoGlobalRepository;
use App\Master\Repository\ConvenioDaUfRepository;
use App\Master\Repository\ConvenioRepository;
use App\Master\Repository\ConvenioVinculoRepository;
use App\Master\Service\ChaveDoConvenio;
use App\Master\Service\ConveniosDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A porta única de leitura dos convênios (spec docs/specs/convenios.md, §3.4), com a decisão de implementação de 24/11:
 * sem vínculo gravado, o convênio OAB vale implícito; `sem` e `dif` da UF só no `oab`; a chave geral tira o que é só
 * exibição.
 */
#[CoversClass(ConveniosDoEscritorio::class)]
#[CoversClass(ConvenioVigente::class)]
final class ConveniosDoEscritorioTest extends TestCase
{
    private const HOJE = '2026-11-24 10:00:00';

    #[TestDox('sem vínculo gravado, o convênio OAB vale implícito e livre, como sempre foi')]
    public function testOabImplicito(): void
    {
        $vigentes = $this->servico()->doTenant(1, 'SP');

        self::assertCount(1, $vigentes);
        self::assertTrue($vigentes[0]->ehOab());
        self::assertTrue($vigentes[0]->implicito);
        self::assertSame(BeneficioDoConvenio::Gratuidade, $vigentes[0]->beneficio);
        self::assertNull($vigentes[0]->modoUf);
    }

    #[TestDox('estado com valor diferenciado: o convênio OAB segue, com o modo e o valor da UF')]
    public function testUfDiferenciada(): void
    {
        $vigentes = $this->servico(excecoes: ['RJ' => $this->excecao('RJ', ConvenioDaUf::MODO_DIFERENCIADO, 9.9)])->doTenant(1, 'RJ');

        self::assertSame([ConvenioDaUf::MODO_DIFERENCIADO, 9.9], [$vigentes[0]->modoUf, $vigentes[0]->valorUf]);
    }

    #[TestDox('estado "sem convênio": o convênio OAB sai; a exceção de outra UF não afeta')]
    public function testUfSemConvenio(): void
    {
        $servico = $this->servico(excecoes: ['MG' => $this->excecao('MG', ConvenioDaUf::MODO_SEM, 0.0)]);

        self::assertSame([], $servico->doTenant(1, 'MG'));
        self::assertCount(1, $servico->doTenant(2, 'SP'));
    }

    #[TestDox('conta sem estado válido: o convênio OAB implícito vale, sem exceção de UF')]
    public function testSemEstado(): void
    {
        $vigentes = $this->servico(excecoes: ['MG' => $this->excecao('MG', ConvenioDaUf::MODO_SEM, 0.0)])->doTenant(1, null);

        self::assertCount(1, $vigentes);
        self::assertNull($vigentes[0]->modoUf);
    }

    #[TestDox('chave geral desligada: a gratuidade sai, o desconto já vinculado segue (§6.1)')]
    public function testChaveDesligada(): void
    {
        $desconto = $this->convenio('caarj', BeneficioDoConvenio::DescontoPercentual, 20);
        $servico = $this->servico(abertos: [1 => [$this->vinculo($desconto)]], comVinculo: [1], chaveLigada: false);

        $vigentes = $servico->dosTenants([1 => 'RJ', 2 => 'SP']);

        self::assertSame(['caarj'], array_map(static fn (ConvenioVigente $v): string => $v->chave, $vigentes[1]));
        self::assertSame([], $vigentes[2]);
        self::assertFalse($servico->exibicaoLigada());
    }

    #[TestDox('escritório com vínculo gravado não ganha o OAB implícito: vale só o vínculo aberto')]
    public function testVinculoGravadoTiraOImplicito(): void
    {
        $outro = $this->convenio('caarj', BeneficioDoConvenio::EspacoExtra, 5);
        $servico = $this->servico(abertos: [1 => [$this->vinculo($outro)]], comVinculo: [1, 2]);

        $vigentes = $servico->dosTenants([1 => 'RJ', 2 => 'SP']);

        self::assertSame('caarj', $vigentes[1][0]->chave);
        self::assertFalse($vigentes[1][0]->implicito);
        self::assertCount(1, $vigentes[1]);
        self::assertSame([], $vigentes[2], 'vínculo OAB fechado: nada de implícito');
    }

    #[TestDox('vínculo aberto do convênio OAB: vale sem ser implícito, com a exceção da UF')]
    public function testVinculoOabAberto(): void
    {
        $oab = $this->oab();
        $servico = $this->servico(oab: $oab, abertos: [1 => [$this->vinculo($oab)]], comVinculo: [1], excecoes: ['RJ' => $this->excecao('RJ', ConvenioDaUf::MODO_DIFERENCIADO, 5.0)]);

        $vigentes = $servico->doTenant(1, 'RJ');

        self::assertCount(1, $vigentes);
        self::assertFalse($vigentes[0]->implicito);
        self::assertSame(ConvenioDaUf::MODO_DIFERENCIADO, $vigentes[0]->modoUf);
    }

    #[TestDox('fora da validade: o convênio não vale (antes do início e depois do fim)')]
    public function testForaDaValidade(): void
    {
        $futuro = new Convenio('futuro', 'Futuro', 'Inst', 'inst', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, BeneficioDoConvenio::EspacoExtra, 5, 'FUTURO', new \DateTimeImmutable('2026-11-25'), new \DateTimeImmutable(self::HOJE));
        $servico = $this->servico(oab: $this->oab('2026-11-25'), abertos: [1 => [$this->vinculo($futuro)]], comVinculo: [1]);

        self::assertSame([], $servico->doTenant(1, 'SP'));
        self::assertSame([], $servico->doTenant(2, 'SP'), 'OAB implícito com início amanhã não vale');
    }

    #[TestDox('depois da primeira sincronização completa, sem vínculo não há OAB implícito; o vínculo aberto vale')]
    public function testDepoisDaSincronizacao(): void
    {
        $oab = $this->oab();
        $servico = $this->servico(oab: $oab, abertos: [1 => [$this->vinculo($oab)]], comVinculo: [1], sincronizado: true);

        $vigentes = $servico->dosTenants([1 => 'SP', 2 => 'SP']);

        self::assertCount(1, $vigentes[1]);
        self::assertFalse($vigentes[1][0]->implicito);
        self::assertSame([], $vigentes[2]);
    }

    #[TestDox('sem a linha do convênio OAB no banco, nada de implícito')]
    public function testSemLinhaOab(): void
    {
        self::assertSame([], $this->servico(oab: false)->doTenant(1, 'SP'));
    }

    #[TestDox('em lote: uma leitura de vínculos para todas as contas')]
    public function testLote(): void
    {
        $vinculos = $this->createMock(ConvenioVinculoRepository::class);
        $vinculos->expects($this->once())->method('abertosDosTenants')->with([1, 2, 3])->willReturn([]);
        $vinculos->expects($this->once())->method('tenantsComVinculo')->with([1, 2, 3])->willReturn([]);

        $vigentes = (new ConveniosDoEscritorio($this->convenios($this->oab()), $vinculos, $this->createStub(ConvenioDaUfRepository::class), $this->chave(true), new MockClock(self::HOJE)))->dosTenants([1 => 'SP', 2 => null, 3 => 'RJ']);

        self::assertSame([1, 2, 3], array_keys($vigentes));
    }

    /**
     * @param array<int, list<ConvenioVinculo>> $abertos
     * @param list<int>                         $comVinculo
     * @param array<string, ConvenioDaUf>       $excecoes
     */
    private function servico(Convenio|false|null $oab = null, array $abertos = [], array $comVinculo = [], array $excecoes = [], bool $chaveLigada = true, bool $sincronizado = false): ConveniosDoEscritorio
    {
        $vinculos = $this->createStub(ConvenioVinculoRepository::class);
        $vinculos->method('abertosDosTenants')->willReturn($abertos);
        $vinculos->method('tenantsComVinculo')->willReturn($comVinculo);
        $porUf = $this->createStub(ConvenioDaUfRepository::class);
        $porUf->method('todosPorUf')->willReturn($excecoes);

        return new ConveniosDoEscritorio($this->convenios($oab === false ? null : ($oab ?? $this->oab())), $vinculos, $porUf, $this->chave($chaveLigada, $sincronizado), new MockClock(self::HOJE));
    }

    private function convenios(?Convenio $oab): ConvenioRepository
    {
        $convenios = $this->createStub(ConvenioRepository::class);
        $convenios->method('porChave')->willReturn($oab);

        return $convenios;
    }

    private function chave(bool $ligada, bool $sincronizado = false): ChaveDoConvenio
    {
        $configuracao = new ConfiguracaoGlobal();
        $configuracao->definirConvenioAtivo($ligada, null, new \DateTimeImmutable(self::HOJE));
        if ($sincronizado) {
            $configuracao->marcarVinculosDeConvenioSincronizados(new \DateTimeImmutable(self::HOJE));
        }
        $configuracoes = $this->createStub(ConfiguracaoGlobalRepository::class);
        $configuracoes->method('atual')->willReturn($configuracao);

        return new ChaveDoConvenio($configuracoes);
    }

    private function oab(string $validoDe = '2026-10-26'): Convenio
    {
        return new Convenio(Convenio::CHAVE_OAB, 'Convênio OAB', 'OAB', 'oab', AbrangenciaDoConvenio::Nacional, null, ProvaDoConvenio::Oab, BeneficioDoConvenio::Gratuidade, 0, 'CONVÊNIO OAB', new \DateTimeImmutable($validoDe), new \DateTimeImmutable(self::HOJE));
    }

    private function convenio(string $chave, BeneficioDoConvenio $beneficio, int $valor): Convenio
    {
        return new Convenio($chave, 'Convênio ' . $chave, 'CAARJ', 'caarj', AbrangenciaDoConvenio::Instituicao, null, ProvaDoConvenio::Codigo, $beneficio, $valor, 'CONVÊNIO CAARJ', new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable(self::HOJE), 'CAARJ2026');
    }

    private function vinculo(Convenio $convenio): ConvenioVinculo
    {
        return new ConvenioVinculo(new Tenant(), $convenio, OrigemDoVinculo::Oab, new \DateTimeImmutable('2026-11-01'));
    }

    private function excecao(string $uf, string $modo, float $valor): ConvenioDaUf
    {
        return new ConvenioDaUf($uf, $modo, $valor, new \DateTimeImmutable('2026-11-01'), null, null, new \DateTimeImmutable(self::HOJE));
    }
}
