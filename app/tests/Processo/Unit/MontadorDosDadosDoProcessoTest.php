<?php

declare(strict_types=1);

namespace App\Tests\Processo\Unit;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Processo\DTO\DadosManuaisDoProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Integracao\IntegracaoComPjeNaoConfigurada;
use App\Processo\Service\MontadorDosDadosDoProcesso;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** P5b: o cartão do processo pela regra do desenho (`pjeDados`): manual prevalece, depois o base. */
#[CoversClass(MontadorDosDadosDoProcesso::class)]
#[CoversClass(Processo::class)]
final class MontadorDosDadosDoProcessoTest extends TestCase
{
    private function processo(): Processo
    {
        $p = new Processo();
        $p->setNumeroProcesso('07011345720258070007');
        $p->setClasseProcessual('PROCEDIMENTO COMUM CÍVEL');
        $p->setClasseCodigo(7);
        $p->setOrgaoJulgador('1ª Vara Cível de Brasília');
        $p->setDataDistribuicao(new \DateTime('2025-03-14 15:30'));

        return $p;
    }

    /** @return array<string, array<string, mixed>> */
    private function campos(Processo $p, ?Pasta $pasta = null): array
    {
        $saida = [];
        foreach ((new MontadorDosDadosDoProcesso(new IntegracaoComPjeNaoConfigurada()))->montar($p, $pasta)->campos as $c) {
            $saida[$c['chave']] = $c;
        }

        return $saida;
    }

    #[TestDox('sem manual: o base do Datajud (classe com código, órgão, distribuição) e o valor da causa da pasta, sem selo')]
    public function testBase(): void
    {
        $pasta = new Pasta();
        $pasta->setValorCausa('15234.50');

        $c = $this->campos($this->processo(), $pasta);

        self::assertSame('PROCEDIMENTO COMUM CÍVEL (7)', $c['classe']['texto']);
        self::assertSame('1ª VARA CÍVEL DE BRASÍLIA', $c['orgao']['texto']);
        self::assertSame('14/03/2025', $c['distribuicao']['texto']);
        self::assertSame('R$ 15.234,50', $c['valorCausa']['texto']);
        self::assertNull($c['jurisdicao']['texto']);
        self::assertNull($c['gratuita']['flag']);
        self::assertFalse($c['classe']['manual']);
    }

    #[TestDox('manual prevalece e ganha o selo; as marcas saem das flags efetivas "Sim", na ordem do desenho')]
    public function testManualPrevalece(): void
    {
        $p = $this->processo();
        $p->definirDadosManuais(new DadosManuaisDoProcesso(
            orgao: '2ª Vara da Fazenda Pública do DF',
            valorCausa: '1000.00',
            segredo: true,
            gratuita: true,
            prioridade: false,
        ), new User());

        $montador = new MontadorDosDadosDoProcesso(new IntegracaoComPjeNaoConfigurada());
        $dados = $montador->montar($p, null);
        $c = $this->campos($p);

        self::assertSame('2ª Vara da Fazenda Pública do DF', $c['orgao']['texto']);
        self::assertTrue($c['orgao']['manual']);
        self::assertSame('R$ 1.000,00', $c['valorCausa']['texto']);
        self::assertFalse($c['prioridade']['flag']);
        self::assertSame(['Justiça gratuita', 'Segredo de justiça'], array_column($dados->marcas, 'rotulo'));
        self::assertSame(['bi-heart-pulse', 'bi-lock'], array_column($dados->marcas, 'icone'));
        self::assertStringStartsWith('Preenchido manualmente em ', $dados->fonteTexto);
        self::assertStringEndsWith(' · integração com o PJe não configurada', $dados->fonteTexto);
        self::assertSame('bi-pencil-square', $dados->fonteIcone);
        self::assertSame(['Órgão julgador', 'Valor da causa', 'Última distribuição'], array_column($dados->resumo, 'rotulo'));
    }

    #[TestDox('sem manual nem integração: a linha de fonte do desenho e o ícone de nuvem cortada')]
    public function testFonteSemNada(): void
    {
        $dados = (new MontadorDosDadosDoProcesso(new IntegracaoComPjeNaoConfigurada()))->montar(new Processo(), null);

        self::assertSame('Integração com o PJe não configurada. Preencha manualmente.', $dados->fonteTexto);
        self::assertSame('bi-cloud-slash', $dados->fonteIcone);
        self::assertFalse($dados->temIntegracao);
        self::assertSame([], $dados->marcas);
    }

    #[TestDox('segredo: o "Sim" do tribunal vence um "Não" manual; o sigilo efetivo é a união dos dois')]
    public function testSegredo(): void
    {
        $p = $this->processo();
        $p->setNivelSigilo(5);
        $p->definirDadosManuais(new DadosManuaisDoProcesso(segredo: false), new User());
        self::assertTrue($this->campos($p)['segredo']['flag']);
        self::assertFalse($this->campos($p)['segredo']['manual'], 'o exibido é o do tribunal: sem selo "manual"');
        self::assertTrue($p->emSegredoDeJustica());

        $publico = $this->processo();
        $publico->setNivelSigilo(0);
        self::assertFalse($this->campos($publico)['segredo']['flag']);
        self::assertFalse($publico->emSegredoDeJustica());
        $publico->definirDadosManuais(new DadosManuaisDoProcesso(segredo: true), new User());
        self::assertTrue($publico->emSegredoDeJustica(), '"Sim" manual também é sigilo');
    }

    #[TestDox('assuntos: lista do Datajud ou o assunto em texto; o formulário recebe um por linha')]
    public function testAssuntos(): void
    {
        $p = $this->processo();
        $p->setAssuntoProcessual('Indenização por Dano Moral');
        $c = $this->campos($p);

        self::assertSame(['INDENIZAÇÃO POR DANO MORAL'], $c['assuntos']['itens']);
        self::assertSame('INDENIZAÇÃO POR DANO MORAL', $c['assuntos']['formulario']);
    }
}
