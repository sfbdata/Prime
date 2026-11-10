<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Support;

use App\Ponto\DTO\CabecalhoDaFolha;
use App\Ponto\DTO\CarimboDoAceiteNaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\IntervalosDaFolha;
use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\DTO\ResumoDoMesDaFolha;
use App\Ponto\DTO\ValidacaoDaFolha;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;

/**
 * Uma folha de outubro de 2026 pronta para desenhar, sem banco: dias úteis com as quatro batidas, fins de semana
 * vazios, o feriado do dia 12 sem batida, um dia fora de sequência (07), um incompleto (20) e uma falta em análise
 * (21). Com ou sem a validação (código BJF) e o carimbo do aceite, e com nome e razão social que não cabem.
 */
final class ConteudoDaFolhaDeExemplo
{
    public const CODIGO = 'BJF-ABCD-EFGH-JK23';
    public const HMAC = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';
    public const RESSALVA = 'O dia 20 foi de trabalho externo, com saída às 17h.';

    public static function outubro(bool $comValidacao = true, ?SituacaoDoAceiteDaFolha $aceite = null, bool $nomesLongos = false): ConteudoDaFolha
    {
        $conteudo = new ConteudoDaFolha(
            2026,
            10,
            self::cabecalho($nomesLongos),
            self::linhas(),
            new ResumoDoMesDaFolha('160:00', '2:07', '+1:00', '-3:00', '-3:00', 1, 1),
            new IntervalosDaFolha(60, ['07'], 660, ['13', '14'], 7, 0, 1),
        );
        if ($aceite !== null) {
            $ressalva = $aceite === SituacaoDoAceiteDaFolha::Ressalva ? self::RESSALVA : null;
            $conteudo = $conteudo->comCarimbo(new CarimboDoAceiteNaFolha($aceite, new \DateTimeImmutable('2026-11-03 09:10:11'), $ressalva));
        }

        return $comValidacao
            ? $conteudo->comValidacao(new ValidacaoDaFolha(self::CODIGO, self::HMAC, new \DateTimeImmutable('2026-11-09 10:15:30')))
            : $conteudo;
    }

    private static function cabecalho(bool $nomesLongos): CabecalhoDaFolha
    {
        $nome = $nomesLongos ? 'MARIA APARECIDA DOS SANTOS FIGUEIREDO DE ALBUQUERQUE CAVALCANTI NASCIMENTO' : 'MARIA DA SILVA';
        $empregador = $nomesLongos ? 'ESCRITÓRIO DE ADVOCACIA FIGUEIREDO ALBUQUERQUE CAVALCANTI E ASSOCIADOS SOCIEDADE DE ADVOGADOS' : 'ESCRITÓRIO MODELO';

        return new CabecalhoDaFolha(
            '01/10/2026',
            '31/10/2026',
            $empregador,
            '12.345.678/0001-90',
            'Quadra QNF 3, 31',
            $nomesLongos ? 'Setor de Habitações Individuais Norte, Conjunto 3, Lote 31, Taguatinga Norte, Taguatinga/DF CEP 72125-530' : 'Taguatinga Norte, Taguatinga/DF CEP 72125-530',
            '40h semanais',
            'Segunda a Sexta',
            '1h00 (mínimo)',
            'mínimo 11h00',
            '0001',
            $nome,
            '***.547.725-**',
            'ADVOGADA',
            '***2329',
            '0060',
            'MACEIÓ/AL',
            '40h',
            '08:00 às 17:00',
            '12:00 às 13:00',
            'Segunda a Sexta (SQQSS)',
            '15/02/2020',
            'FARLEI ASSIS DA ROCHA OAB/DF 49.613',
        );
    }

    /** @return list<LinhaDaFolhaImpressa> */
    private static function linhas(): array
    {
        $linhas = [];
        $semana = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        for ($dia = 1; $dia <= 31; ++$dia) {
            $data = new \DateTimeImmutable(sprintf('2026-10-%02d', $dia));
            $util = (int) $data->format('N') <= 5;
            $linhas[] = match (true) {
                $dia === 12 => self::linha($data, 'FERIADO', true, ['', '', '', ''], '', '', '+0:00', 'Feriado: Nossa Senhora Aparecida', feriado: 'Nossa Senhora Aparecida'),
                $dia === 7 => self::linha($data, $semana[3], false, ['08:00', '12:30', '12:10', '17:00'], '8:20', '0:20', '+0:20', 'Horário fora de sequência: revisar', fora: true),
                $dia === 20 => self::linha($data, $semana[2], false, ['08:00', '12:00', '13:00', ''], '', '', '+0:20', 'Registro incompleto', incompleto: true),
                $dia === 21 => self::linha($data, $semana[3], false, ['', '', '', ''], '0:00', '0:00', '-7:40', 'Falta: em análise', negativo: true),
                $util => self::linha($data, $semana[(int) $data->format('N')], false, ['08:00', '12:00', '13:00', '17:00'], '8:00', '0:00', '+0:20', ''),
                default => self::linha($data, $semana[(int) $data->format('N')], false, ['', '', '', ''], '0:00', '0:00', '+0:20', ''),
            };
        }

        return $linhas;
    }

    /** @param list<string> $batidas */
    private static function linha(\DateTimeImmutable $data, string $semana, bool $feriadoSemBatida, array $batidas, string $trabalhadas, string $extras, string $saldo, string $observacao, bool $fora = false, bool $incompleto = false, bool $negativo = false, ?string $feriado = null): LinhaDaFolhaImpressa
    {
        return new LinhaDaFolhaImpressa(
            $data->format('Y-m-d'),
            $data->format('d'),
            $semana,
            $feriadoSemBatida,
            $batidas,
            $trabalhadas,
            $extras,
            $saldo,
            $negativo,
            $observacao,
            $fora,
            $incompleto,
            $feriado,
            $observacao === 'Falta: em análise' ? ['pendente'] : [],
        );
    }
}
