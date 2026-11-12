<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\DTO\ResultadoDaOpcao;
use App\Voto\DTO\ResultadoDaPauta;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Enum\BaseDaDecisao;
use App\Voto\Enum\RegraDeDecisao;

/**
 * Apura uma pauta pelos contadores das opções (spec docs/specs/voto-assembleia-condominio.md, seção 1 item 9, e
 * docs/specs/voto-pautas-reaproveitamento-1111.md; regra do desenho, `avaliar` do bj-voto-nucleo.js):
 *
 *  - votos válidos = peso das opções (a abstenção não é voto válido);
 *  - presentes = peso das unidades que participaram (`$presenca`); sem ele, peso das opções + abstenções, que só
 *    é igual quando cada unidade escolhe uma opção (vaga única; várias vagas exigem `$presenca`). Quórum = presentes × 100 ÷ peso apto (0 sem peso
 *    apto); quórum ok = quórum ≥ quórum exigido da pauta (null conta como 0);
 *  - opções por peso decrescente; no empate de peso, pelo número;
 *  - vaga única, critério sobre a base da pauta (válidos, presentes ou aptos): "mais votos" (o 1º tem peso > 0),
 *    "maioria absoluta" (1º × 2 > base), "dois terços" (1º × 3 ≥ base × 2) e "unanimidade" (1º ≥ base); empate =
 *    1º e 2º com o mesmo peso, sem voto de qualidade;
 *  - várias vagas: os N mais votados com peso > 0, em ordem; critério e base não se aplicam (como no desenho); empate
 *    na última vaga = o próximo fora do corte tem o mesmo peso do último dentro, e ninguém é proclamado;
 *  - decidida só com quórum ok, sem empate e com ao menos um eleito;
 *  - situação: "Quórum não atingido", senão "Empate" (ou "Empate na última vaga"), senão "Decidido", senão
 *    "Critério não atingido".
 *
 * Tudo em bcmath sobre string decimal: somar frações em float erraria a última casa e poderia virar o resultado.
 * Lê os contadores da instância em memória: quem acabou de votar na mesma requisição relê a pauta antes.
 */
final class ApuracaoDaPauta
{
    private const ESCALA = 6;
    private const ESCALA_DA_CONTA = 12;

    /**
     * @param string                                          $pesoApto      soma do peso das unidades aptas (UnidadeDaAssembleiaRepository::totaisAptos())
     * @param int                                             $unidadesAptas número de unidades aptas
     * @param array{unidades: int, peso: string}|null         $presenca      unidades que participaram e o peso delas (ParticipacaoNaPautaRepository::contarPorPauta())
     */
    public function apurar(PautaDaAssembleia $pauta, string $pesoApto, int $unidadesAptas, ?array $presenca = null): ResultadoDaPauta
    {
        self::exigirPresencaSeVariasVagas($pauta, $presenca);
        $pesoApto = self::normalizar($pesoApto);
        $opcoes = self::ordenar($pauta->getOpcoesDeVoto());
        $pesoValido = self::somarPesos($opcoes);
        $abstencao = $pauta->getAbstencao();
        $abstencoesPeso = self::normalizar($abstencao?->getTotalPeso() ?? '0');
        $pesoPresente = null === $presenca ? bcadd($pesoValido, $abstencoesPeso, self::ESCALA) : self::normalizar($presenca['peso']);
        $quorumExigido = $pauta->getQuorumExigido() ?? '0';
        $quorumOk = self::quorumAtingido($pesoPresente, $pesoApto, $quorumExigido);
        $resultados = array_map(static fn (OpcaoDaPauta $o): ResultadoDaOpcao => self::resultadoDaOpcao($o, $pesoValido), $opcoes);
        $base = self::valorDaBase($pauta->getBase(), $pesoValido, $pesoPresente, $pesoApto);
        $vagas = $pauta->getVagas();
        [$empate, $eleitos] = 1 === $vagas
            ? self::vagaUnica($opcoes, $resultados, $pauta->getCriterio(), $base)
            : self::varias($resultados, $vagas);
        $vencedores = $quorumOk && !$empate ? $eleitos : [];

        return new ResultadoDaPauta(
            situacao: self::situacao($quorumOk, $empate, [] !== $vencedores, $vagas),
            vencedor: $vencedores[0] ?? null,
            opcoes: $resultados,
            empate: $empate,
            abstencoesUnidades: $abstencao?->getTotalUnidades() ?? 0,
            abstencoesPeso: $abstencoesPeso,
            votantes: $presenca['unidades'] ?? self::votantes($pauta),
            pesoValido: $pesoValido,
            pesoPresente: $pesoPresente,
            pesoApto: $pesoApto,
            unidadesAptas: $unidadesAptas,
            quorumPct: self::percentual($pesoPresente, $pesoApto),
            quorumExigido: bcadd($quorumExigido, '0', 2),
            quorumOk: $quorumOk,
            vencedores: $vencedores,
            criterio: $pauta->getCriterio(),
            base: $pauta->getBase(),
            vagas: $vagas,
        );
    }

    /**
     * Sem as participações, somar as escolhas contaria a mesma unidade várias vezes.
     *
     * @param array{unidades: int, peso: string}|null $presenca
     */
    private static function exigirPresencaSeVariasVagas(PautaDaAssembleia $pauta, ?array $presenca): void
    {
        if (null === $presenca && $pauta->getVagas() > 1) {
            throw new \LogicException('Pauta com várias vagas só se apura com a presença vinda das participações.');
        }
    }

    private static function valorDaBase(BaseDaDecisao $base, string $pesoValido, string $pesoPresente, string $pesoApto): string
    {
        return match ($base) {
            BaseDaDecisao::Validos => $pesoValido,
            BaseDaDecisao::Presentes => $pesoPresente,
            BaseDaDecisao::Aptos => $pesoApto,
        };
    }

    /**
     * Vaga única: empate do 1º com o 2º; eleito o 1º que tem peso e cumpre o critério sobre a base.
     *
     * @param list<OpcaoDaPauta>     $opcoes
     * @param list<ResultadoDaOpcao> $resultados
     *
     * @return array{bool, list<ResultadoDaOpcao>}
     */
    private static function vagaUnica(array $opcoes, array $resultados, RegraDeDecisao $criterio, string $base): array
    {
        $empate = isset($opcoes[0], $opcoes[1]) && 0 === bccomp($opcoes[0]->getTotalPeso(), $opcoes[1]->getTotalPeso(), self::ESCALA);
        $primeiro = $resultados[0] ?? null;
        if (null === $primeiro || bccomp($primeiro->peso, '0', self::ESCALA) <= 0 || !self::criterioAtingido($criterio, $primeiro->peso, $base)) {
            return [$empate, []];
        }

        return [$empate, [$primeiro]];
    }

    private static function criterioAtingido(RegraDeDecisao $criterio, string $peso, string $base): bool
    {
        return match ($criterio) {
            RegraDeDecisao::Mais => true,
            RegraDeDecisao::Absoluta => bccomp(bcmul($peso, '2', self::ESCALA_DA_CONTA), $base, self::ESCALA_DA_CONTA) > 0,
            RegraDeDecisao::DoisTercos => bccomp(bcmul($peso, '3', self::ESCALA_DA_CONTA), bcmul($base, '2', self::ESCALA_DA_CONTA), self::ESCALA_DA_CONTA) >= 0,
            RegraDeDecisao::Unanimidade => bccomp($peso, $base, self::ESCALA_DA_CONTA) >= 0,
        };
    }

    /**
     * Várias vagas: os N mais votados com peso positivo; empate se o próximo fora do corte iguala o último dentro.
     *
     * @param list<ResultadoDaOpcao> $resultados por peso decrescente
     *
     * @return array{bool, list<ResultadoDaOpcao>}
     */
    private static function varias(array $resultados, int $vagas): array
    {
        $comVoto = [];
        foreach ($resultados as $resultado) {
            if (bccomp($resultado->peso, '0', self::ESCALA) > 0) {
                $comVoto[] = $resultado;
            }
        }
        $corte = \array_slice($comVoto, 0, $vagas);
        $proximo = $comVoto[$vagas] ?? null;
        $empate = null !== $proximo && [] !== $corte && 0 === bccomp($proximo->peso, $corte[\count($corte) - 1]->peso, self::ESCALA);

        return [$empate, $corte];
    }

    private static function situacao(bool $quorumOk, bool $empate, bool $temVencedor, int $vagas): string
    {
        if (!$quorumOk) {
            return ResultadoDaPauta::QUORUM_NAO_ATINGIDO;
        }
        if ($empate) {
            return $vagas > 1 ? ResultadoDaPauta::EMPATE_NA_ULTIMA_VAGA : ResultadoDaPauta::EMPATE;
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
