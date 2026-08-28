<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CasoDetalheOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A barra de composição do vencido (redesenho 1a) desenha três segmentos com a largura que este método
 * devolve. A propriedade que ele existe para garantir é UMA: os três SEMPRE somam exatamente 100.
 *
 * Não é preciosismo. A barra é `display:flex` com `overflow:hidden`: somando 99,9 sobra uma fresta de
 * fundo cinza na ponta direita, que lê como "falta alguma coisa"; somando 100,1 o último segmento é
 * cortado e a proporção exibida deixa de ser a real. Três `round()` independentes produzem os dois
 * casos — daí o terceiro ser o RESTO, e daí este teste.
 */
#[CoversClass(CasoDetalheOutput::class)]
final class ComposicaoVencidoTest extends TestCase
{
    /** Só os quatro campos do vencido importam aqui; o resto do DTO vai no mínimo viável. */
    private function caso(int $principal, int $encargos, int $honorarios): CasoDetalheOutput
    {
        return new CasoDetalheOutput(
            id: 1,
            objetoIdentificacao: '03-07',
            objetoDescricao: null,
            carteiraId: 1,
            carteiraNome: 'TOP LIFE I',
            pessoaCobradaNome: 'Fulano',
            pessoaCobradaCpf: null,
            pessoaCobradaCnpj: null,
            pessoaCobradaEmail: null,
            pessoaCobradaTelefone: null,
            statusLabel: 'Ativo',
            statusBadgeClass: 'text-bg-success',
            encerrado: false,
            prontoParaEncerrar: false,
            saldoExigivel: 0,
            formaHonorariosLabel: '—',
            percentualHonorarios: null,
            pastaJudicialId: null,
            proximaAcao: null,
            alertas: [],
            obrigacoes: [],
            gruposAcordo: [],
            obrigacoesAvulsas: [],
            pagamentos: [],
            liquidacoes: [],
            acordos: [],
            historico: [],
            totalPrincipalVencido: $principal,
            totalEncargosVencido: $encargos,
            honorariosVencidos: $honorarios,
            totalAtualizadoVencido: $principal + $encargos + $honorarios,
        );
    }

    #[Test]
    #[TestDox('Os três segmentos somam exatamente 100 — inclusive quando cada um, arredondado sozinho, não fecharia')]
    public function osTresSegmentosSomamCem(): void
    {
        // Casos escolhidos por quebrarem a soma se os três fossem arredondados de forma independente.
        $cenarios = [
            'terços exatos' => [10000, 10000, 10000],
            'dízima simples' => [10000, 10000, 10001],
            'do desenho (59,5 / 23,8 / 16,7)' => [2240000, 897821, 627575],
            'um encargo esmagado' => [99999900, 1, 99],
            'um centavo em cada' => [1, 1, 1],
        ];

        foreach ($cenarios as $nome => [$p, $e, $h]) {
            $comp = $this->caso($p, $e, $h)->composicaoVencido();

            self::assertSame(
                100.0,
                round($comp['principal'] + $comp['encargos'] + $comp['honorarios'], 1),
                "os três segmentos têm de fechar 100% no cenário \"{$nome}\"",
            );
        }
    }

    #[Test]
    #[TestDox('Cada segmento é a proporção do seu campo — a barra não inverte nem embaralha as fatias')]
    public function cadaSegmentoEAProporcaoDoSeuCampo(): void
    {
        // 50% / 30% / 20% de R$ 1.000,00 — números redondos, sem arredondamento no caminho.
        $comp = $this->caso(50000, 30000, 20000)->composicaoVencido();

        self::assertSame(50.0, $comp['principal']);
        self::assertSame(30.0, $comp['encargos']);
        self::assertSame(20.0, $comp['honorarios']);
    }

    #[Test]
    #[TestDox('Sem vencido os três são zero — e o Twig não desenha a barra')]
    public function semVencidoNaoHaComposicao(): void
    {
        $comp = $this->caso(0, 0, 0)->composicaoVencido();

        self::assertSame(['principal' => 0.0, 'encargos' => 0.0, 'honorarios' => 0.0], $comp);
    }

    #[Test]
    #[TestDox('Divisão por zero não acontece nem com os componentes preenchidos e o total zerado')]
    public function totalZeradoNaoDivideProZero(): void
    {
        // Estado impossível pela identidade do UseCase, mas o DTO aceita os quatro campos soltos: se um
        // dia alguém montar um assim, o guard tem de responder zeros em vez de estourar.
        $caso = new CasoDetalheOutput(
            id: 1, objetoIdentificacao: 'X', objetoDescricao: null, carteiraId: 1, carteiraNome: 'C',
            pessoaCobradaNome: 'F', pessoaCobradaCpf: null, pessoaCobradaCnpj: null,
            pessoaCobradaEmail: null, pessoaCobradaTelefone: null,
            statusLabel: 'Ativo', statusBadgeClass: 'text-bg-success', encerrado: false,
            prontoParaEncerrar: false, saldoExigivel: 0, formaHonorariosLabel: '—',
            percentualHonorarios: null, pastaJudicialId: null, proximaAcao: null,
            alertas: [], obrigacoes: [], gruposAcordo: [], obrigacoesAvulsas: [], pagamentos: [],
            liquidacoes: [], acordos: [], historico: [],
            totalPrincipalVencido: 12345,
            totalAtualizadoVencido: 0,
        );

        self::assertSame(['principal' => 0.0, 'encargos' => 0.0, 'honorarios' => 0.0], $caso->composicaoVencido());
    }
}
