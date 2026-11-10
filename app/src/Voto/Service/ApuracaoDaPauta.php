<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\ResultadoDaOpcao;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;

/**
 * Apura uma pauta pelos contadores das opções (spec docs/specs/voto-assembleia-condominio.md, seção 1 item 9; regra do
 * desenho, docs/specs/voto-assembleia-desenho.md §5, `avaliar` do bj-voto-nucleo.js):
 *
 *  - critério "Mais votos" sobre os votos válidos (a abstenção não é voto válido);
 *  - presentes = peso das opções + peso das abstenções; quórum = presentes × 100 ÷ peso apto (0 sem peso apto);
 *    quórum ok = quórum ≥ quórum exigido da pauta (null conta como 0);
 *  - opções por peso decrescente; no empate de peso, pelo número;
 *  - empate = 1º e 2º com o mesmo peso (sem voto de qualidade);
 *  - vencedor só com quórum ok, sem empate e peso do 1º > 0;
 *  - situação: "Quórum não atingido", senão "Empate", senão "Decidido", senão "Critério não atingido".
 *
 * Tudo em bcmath sobre string decimal: somar frações em float erraria a última casa e poderia virar o resultado.
 * Lê os contadores da instância em memória: quem acabou de votar na mesma requisição relê a pauta antes.
 */
final class ApuracaoDaPauta
{
    private const ESCALA = 6;
    private const ESCALA_DA_CONTA = 12;

    /**
     * @param string $pesoApto      soma do peso das unidades aptas (UnidadeDaAssembleiaRepository::totaisAptos())
     * @param int    $unidadesAptas número de unidades aptas
     */
    public function apurar(PautaDaAssembleia $pauta, string $pesoApto, int $unidadesAptas): ResultadoDaPauta
    {
        $pesoApto = self::normalizar($pesoApto);
        $opcoes = self::ordenar($pauta->getOpcoesDeVoto());
        $pesoValido = self::somarPesos($opcoes);
        $abstencao = $pauta->getAbstencao();
        $abstencoesPeso = self::normalizar($abstencao?->getTotalPeso() ?? '0');
        $pesoPresente = bcadd($pesoValido, $abstencoesPeso, self::ESCALA);
        $quorumExigido = $pauta->getQuorumExigido() ?? '0';
        $quorumOk = self::quorumAtingido($pesoPresente, $pesoApto, $quorumExigido);
        $empate = isset($opcoes[0], $opcoes[1]) && 0 === bccomp($opcoes[0]->getTotalPeso(), $opcoes[1]->getTotalPeso(), self::ESCALA);
        $resultados = array_map(static fn (OpcaoDaPauta $o): ResultadoDaOpcao => self::resultadoDaOpcao($o, $pesoValido), $opcoes);
        $vencedor = $quorumOk && !$empate && isset($resultados[0]) && bccomp($resultados[0]->peso, '0', self::ESCALA) > 0
            ? $resultados[0]
            : null;

        return new ResultadoDaPauta(
            situacao: self::situacao($quorumOk, $empate, null !== $vencedor),
            vencedor: $vencedor,
            opcoes: $resultados,
            empate: $empate,
            abstencoesUnidades: $abstencao?->getTotalUnidades() ?? 0,
            abstencoesPeso: $abstencoesPeso,
            votantes: self::votantes($pauta),
            pesoValido: $pesoValido,
            pesoPresente: $pesoPresente,
            pesoApto: $pesoApto,
            unidadesAptas: $unidadesAptas,
            quorumPct: self::percentual($pesoPresente, $pesoApto),
            quorumExigido: bcadd($quorumExigido, '0', 2),
            quorumOk: $quorumOk,
        );
    }

    private static function situacao(bool $quorumOk, bool $empate, bool $temVencedor): string
    {
        if (!$quorumOk) {
            return ResultadoDaPauta::QUORUM_NAO_ATINGIDO;
        }
        if ($empate) {
            return ResultadoDaPauta::EMPATE;
        }

        return $temVencedor ? ResultadoDaPauta::DECIDIDO : ResultadoDaPauta::CRITERIO_NAO_ATINGIDO;
    }

    /** Sem peso apto o quórum é 0%: só passa se o exigido for 0 (mesma conta do desenho). */
    private static function quorumAtingido(string $pesoPresente, string $pesoApto, string $quorumExigido): bool
    {
        if (bccomp($pesoApto, '0', self::ESCALA) <= 0) {
            return bccomp('0', $quorumExigido, self::ESCALA) >= 0;
        }
        $presenteVezesCem = bcmul($pesoPresente, '100', self::ESCALA_DA_CONTA);
        $exigidoVezesApto = bcmul($quorumExigido, $pesoApto, self::ESCALA_DA_CONTA);

        return bccomp($presenteVezesCem, $exigidoVezesApto, self::ESCALA_DA_CONTA) >= 0;
    }

    private static function resultadoDaOpcao(OpcaoDaPauta $opcao, string $pesoValido): ResultadoDaOpcao
    {
        $peso = self::normalizar($opcao->getTotalPeso());

        return new ResultadoDaOpcao(
            numero: $opcao->getNumero(),
            rotulo: $opcao->getRotulo(),
            unidades: $opcao->getTotalUnidades(),
            peso: $peso,
            percentual: self::percentual($peso, $pesoValido),
        );
    }

    /**
     * @param list<OpcaoDaPauta> $opcoes
     *
     * @return list<OpcaoDaPauta>
     */
    private static function ordenar(array $opcoes): array
    {
        usort($opcoes, static fn (OpcaoDaPauta $a, OpcaoDaPauta $b): int => bccomp($b->getTotalPeso(), $a->getTotalPeso(), self::ESCALA)
            ?: $a->getNumero() <=> $b->getNumero());

        return $opcoes;
    }

    /** @param list<OpcaoDaPauta> $opcoes */
    private static function somarPesos(array $opcoes): string
    {
        $soma = '0';
        foreach ($opcoes as $opcao) {
            $soma = bcadd($soma, $opcao->getTotalPeso(), self::ESCALA);
        }

        return self::normalizar($soma);
    }

    private static function votantes(PautaDaAssembleia $pauta): int
    {
        $total = 0;
        foreach ($pauta->getOpcoes() as $opcao) {
            $total += $opcao->getTotalUnidades();
        }

        return $total;
    }

    /** parte × 100 ÷ todo, com 2 casas arredondadas (meio para cima); 0.00 quando o todo é 0. */
    private static function percentual(string $parte, string $todo): string
    {
        if (bccomp($todo, '0', self::ESCALA) <= 0) {
            return '0.00';
        }
        $bruto = bcdiv(bcmul($parte, '100', self::ESCALA_DA_CONTA), $todo, self::ESCALA_DA_CONTA);

        return bcadd($bruto, '0.005', 2);
    }

    private static function normalizar(string $decimal): string
    {
        return bcadd($decimal, '0', self::ESCALA);
    }
}
