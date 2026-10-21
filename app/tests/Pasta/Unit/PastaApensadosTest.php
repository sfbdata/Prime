<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\Exception\ApensadoInvalidoException;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Regras do apensado na entidade (P4f): quem é principal, quem é apensado e o que se recusa ao vincular.
 */
#[CoversClass(Pasta::class)]
#[Group('pasta')]
final class PastaApensadosTest extends TestCase
{
    #[TestDox('Apensado entra com o tipo escolhido e nunca como principal')]
    public function testApensadoNaoViraPrincipal(): void
    {
        $pasta     = new Pasta();
        $principal = $this->processo(1);
        $embargos  = $this->processo(2);
        $pasta->vincularProcesso($principal);

        $vinculo = $pasta->vincularApensado($embargos, TipoDeApenso::Embargos);

        self::assertFalse($vinculo->isPrincipal());
        self::assertSame(TipoDeApenso::Embargos, $vinculo->getTipoApenso());
        self::assertSame($principal, $pasta->getProcessoPrincipal());
        self::assertSame([$vinculo], $pasta->getApensados());
        self::assertTrue($pasta->ehApensado($embargos));
        self::assertFalse($pasta->ehApensado($principal));
    }

    #[TestDox('Sem principal, o apensado é aceito e a pasta continua sem principal (N:4555)')]
    public function testApensadoSemPrincipal(): void
    {
        $pasta = new Pasta();
        $pasta->vincularApensado($this->processo(1), TipoDeApenso::Recurso);

        self::assertNull($pasta->getVinculoPrincipal());
        self::assertCount(1, $pasta->getApensados());
    }

    #[TestDox('Depois de só apensados, o próximo "Vincular processo" vira o principal')]
    public function testVincularProcessoDepoisDeApensadoViraPrincipal(): void
    {
        $pasta = new Pasta();
        $pasta->vincularApensado($this->processo(1), TipoDeApenso::Recurso);

        $vinculo = $pasta->vincularProcesso($this->processo(2));

        self::assertTrue($vinculo->isPrincipal());
    }

    #[TestDox('Recusa o processo principal: "Este é o processo principal da pasta."')]
    public function testRecusaOPrincipal(): void
    {
        $pasta     = new Pasta();
        $principal = $this->processo(1);
        $pasta->vincularProcesso($principal);

        $this->expectException(ApensadoInvalidoException::class);
        $this->expectExceptionMessage('Este é o processo principal da pasta.');

        $pasta->vincularApensado($principal, TipoDeApenso::Embargos);
    }

    #[TestDox('Recusa o apensado repetido: "Este apensado já está vinculado." (também o vínculo antigo sem tipo)')]
    public function testRecusaApensadoRepetido(): void
    {
        $pasta  = new Pasta();
        $antigo = $this->processo(2);
        $pasta->vincularProcesso($this->processo(1));
        $pasta->vincularProcesso($antigo); // vínculo antigo, sem tipo: é apensado "Outro"

        $this->expectException(ApensadoInvalidoException::class);
        $this->expectExceptionMessage('Este apensado já está vinculado.');

        $pasta->vincularApensado($antigo, TipoDeApenso::Embargos);
    }

    #[TestDox('Desvincular o principal não promove apensado com tipo; promove o vínculo antigo sem tipo')]
    public function testPromocaoIgnoraApensadoComTipo(): void
    {
        $pasta     = new Pasta();
        $principal = $this->processo(1);
        $pasta->vincularProcesso($principal);
        $pasta->vincularApensado($this->processo(2), TipoDeApenso::Embargos);

        $pasta->desvincularProcesso($principal);

        self::assertNull($pasta->getVinculoPrincipal());
        self::assertCount(1, $pasta->getApensados());

        $antigo = $this->processo(3);
        $pasta->vincularProcesso($antigo);
        self::assertSame($antigo, $pasta->getProcessoPrincipal());
    }

    #[TestDox('Tornar um apensado principal tira o tipo dele; o antigo principal vira apensado "Outro"')]
    public function testTornarApensadoPrincipal(): void
    {
        $pasta     = new Pasta();
        $principal = $this->processo(1);
        $embargos  = $this->processo(2);
        $pasta->vincularProcesso($principal);
        $pasta->vincularApensado($embargos, TipoDeApenso::Embargos);

        $pasta->definirProcessoPrincipal($embargos);

        self::assertSame($embargos, $pasta->getProcessoPrincipal());
        self::assertNull($pasta->getVinculoPrincipal()?->getTipoApenso());
        self::assertSame('Outro', $pasta->getApensados()[0]->getTipoApensoRotulo());
    }

    private function processo(int $id): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(str_pad((string) $id, 20, '0', STR_PAD_LEFT));
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, $id);

        return $processo;
    }
}
