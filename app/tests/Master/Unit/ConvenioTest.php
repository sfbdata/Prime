<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Tenant\Tenant;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Enum\ProvaDoConvenio;
use App\Master\Exception\ConvenioRecusado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras das CHECK das tabelas `convenio` e `convenio_vinculo` (spec docs/specs/convenios.md, §3.1 e §3.2),
 * conferidas também na entidade para a recusa ter texto.
 */
#[CoversClass(Convenio::class)]
#[CoversClass(ConvenioVinculo::class)]
final class ConvenioTest extends TestCase
{
    #[TestDox('o Convênio OAB de hoje: nacional, pela OAB, gratuidade, vigente desde 26/10')]
    public function testOab(): void
    {
        $oab = $this->convenio();

        self::assertTrue($oab->ehOab());
        self::assertTrue($oab->isAtivo());
        self::assertFalse($oab->getBeneficio()->ehReal());
        self::assertFalse($oab->vigenteEm(new \DateTimeImmutable('2026-10-25 23:59:59')));
        self::assertTrue($oab->vigenteEm(new \DateTimeImmutable('2026-10-26 00:00:00')));
    }

    #[TestDox('convênio de um estado: vale só com a UF certa')]
    public function testDeUmEstado(): void
    {
        $doRj = $this->convenio(chave: 'oab-rj', abrangencia: AbrangenciaDoConvenio::Uf, uf: 'RJ');

        self::assertSame('RJ', $doRj->getUf());
        self::assertFalse($doRj->ehOab());
    }

    #[TestDox('benefício real: desconto e espaço contam como benefício; a gratuidade não')]
    public function testBeneficioReal(): void
    {
        self::assertTrue(BeneficioDoConvenio::DescontoPercentual->ehReal());
        self::assertTrue(BeneficioDoConvenio::DescontoFixo->ehReal());
        self::assertTrue(BeneficioDoConvenio::EspacoExtra->ehReal());
    }

    /** @return iterable<string, array{\Closure(self): Convenio}> */
    public static function recusas(): iterable
    {
        yield 'estado sem abrangência de estado' => [static fn (self $t): Convenio => $t->convenio(uf: 'RJ')];
        yield 'abrangência de estado sem estado' => [static fn (self $t): Convenio => $t->convenio(abrangencia: AbrangenciaDoConvenio::Uf)];
        yield 'estado inválido' => [static fn (self $t): Convenio => $t->convenio(abrangencia: AbrangenciaDoConvenio::Uf, uf: 'XX')];
        yield 'percentual zero' => [static fn (self $t): Convenio => $t->convenio(beneficio: BeneficioDoConvenio::DescontoPercentual, valor: 0)];
        yield 'percentual acima de 100' => [static fn (self $t): Convenio => $t->convenio(beneficio: BeneficioDoConvenio::DescontoPercentual, valor: 101)];
        yield 'valor negativo' => [static fn (self $t): Convenio => $t->convenio(beneficio: BeneficioDoConvenio::DescontoFixo, valor: -1)];
        yield 'prova por código sem código' => [static fn (self $t): Convenio => $t->convenio(prova: ProvaDoConvenio::Codigo)];
        yield 'código com prova pela OAB' => [static fn (self $t): Convenio => $t->convenio(codigo: 'OAB2026')];
        yield 'nome vazio' => [static fn (self $t): Convenio => $t->convenio(nome: '  ')];
        yield 'selo com mais de 40 caracteres' => [static fn (self $t): Convenio => $t->convenio(selo: str_repeat('A', 41))];
    }

    /** @param \Closure(self): Convenio $criar */
    #[DataProvider('recusas')]
    #[TestDox('recusa convênio inválido: $_dataName')]
    public function testRecusa(\Closure $criar): void
    {
        $this->expectException(ConvenioRecusado::class);

        $criar($this);
    }

    #[TestDox('desconto de 100% e código com prova por código são aceitos')]
    public function testLimitesAceitos(): void
    {
        $convenio = $this->convenio(chave: 'caarj', prova: ProvaDoConvenio::Codigo, beneficio: BeneficioDoConvenio::DescontoPercentual, valor: 100, codigo: 'CAARJ2026');

        self::assertSame([100, 'CAARJ2026'], [$convenio->getBeneficioValor(), $convenio->getCodigoConvite()]);
    }

    #[TestDox('vínculo: nasce aberto, encerra uma vez com motivo e fica como histórico')]
    public function testVinculoEncerra(): void
    {
        $vinculo = new ConvenioVinculo(new Tenant(), $this->convenio(), OrigemDoVinculo::Oab, new \DateTimeImmutable('2026-11-01 10:00:00'));
        self::assertTrue($vinculo->estaAberto());

        $vinculo->encerrar(MotivoDoFimDoVinculo::EstadoMudou, new \DateTimeImmutable('2026-11-24 10:00:00'));

        self::assertFalse($vinculo->estaAberto());
        self::assertSame(MotivoDoFimDoVinculo::EstadoMudou, $vinculo->getMotivoFim());
        $this->expectException(ConvenioRecusado::class);
        $vinculo->encerrar(MotivoDoFimDoVinculo::Master, new \DateTimeImmutable('2026-11-25 10:00:00'));
    }

    #[TestDox('vínculo: o fim não pode ser antes do início')]
    public function testVinculoFimAntesDoInicio(): void
    {
        $vinculo = new ConvenioVinculo(new Tenant(), $this->convenio(), OrigemDoVinculo::Master, new \DateTimeImmutable('2026-11-24 10:00:00'));

        $this->expectException(ConvenioRecusado::class);
        $vinculo->encerrar(MotivoDoFimDoVinculo::Saiu, new \DateTimeImmutable('2026-11-24 09:59:59'));
    }

    public function convenio(
        string $chave = Convenio::CHAVE_OAB,
        string $nome = 'Convênio OAB',
        AbrangenciaDoConvenio $abrangencia = AbrangenciaDoConvenio::Nacional,
        ?string $uf = null,
        ProvaDoConvenio $prova = ProvaDoConvenio::Oab,
        BeneficioDoConvenio $beneficio = BeneficioDoConvenio::Gratuidade,
        int $valor = 0,
        string $selo = 'CONVÊNIO OAB',
        ?string $codigo = null,
    ): Convenio {
        return new Convenio($chave, $nome, 'OAB', 'oab', $abrangencia, $uf, $prova, $beneficio, $valor, $selo, new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-24 10:00:00'), $codigo);
    }
}
