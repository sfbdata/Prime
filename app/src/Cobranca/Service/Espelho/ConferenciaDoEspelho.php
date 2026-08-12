<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Espelho;

use App\Cobranca\DTO\ResultadoConferencia;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\Importacao\IdentificacaoDaUnidade;

/**
 * Compara o que a contabilidade cobra com o que o sistema tem
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §5).
 *
 * É a resposta para a pergunta que originou toda esta frente — *"o sistema está alinhado com a
 * contabilidade?"* —, e até aqui ela só podia ser respondida abrindo o Excel na mão.
 *
 * **Somente leitura.** Não escreve nada, em nenhuma tabela.
 *
 * 🔑 A regra de ramo — boleto comum soma só as classes de principal, parcela de acordo soma todas —
 * mora no {@see AgrupadorDeBoletos}, compartilhado com a calibração. Duas cópias de regra de dinheiro
 * divergem em silêncio, e foi essa a lição do D10.
 */
final class ConferenciaDoEspelho
{
    public function __construct(
        private readonly AgrupadorDeBoletos $agrupador,
        private readonly ObrigacaoRepository $obrigacoes,
        private readonly ObjetoCobrancaRepository $objetos,
    ) {
    }

    public function conferir(RelatorioImportado $relatorio): ResultadoConferencia
    {
        $carteira = $relatorio->getCarteira();
        $dadosAte = $relatorio->getDadosAte();

        if ($carteira === null || $dadosAte === null) {
            throw new \LogicException(
                'Lote sem carteira ou sem a data "Inadimplência até" — não há como recortar o universo do sistema.'
            );
        }

        $grupos = $this->agrupador->agrupar($relatorio);
        $sistema = $this->indexarSistema($carteira, $dadosAte);
        $cadastro = $this->objetos->identificacoesPorSituacaoDeCaso($carteira);

        $confere = 0;
        $falta = [];
        $principalDiferente = [];
        $ambiguidade = [];
        $vistos = [];
        $principalRelatorio = 0;

        foreach ($grupos as $chave => $grupo) {
            $principalRelatorio += $grupo['principal'];

            if (count($sistema['casosPorUnidade'][$grupo['unidade']] ?? []) > 1) {
                // D11: objeto com mais de um caso. Escolher um produziria divergência falsa e
                // silenciosa — reportar é a única saída honesta.
                $ambiguidade[] = $chave;

                continue;
            }

            if (!isset($sistema['porChave'][$chave])) {
                $falta[] = [
                    'chave' => $chave,
                    'unidade' => $grupo['unidade'],
                    'nn' => $grupo['nn'],
                    'competencia' => $grupo['competencia'],
                    'motivo' => $this->motivoDaFalta($grupo['unidade'], $cadastro),
                ];

                continue;
            }

            $vistos[$chave] = true;
            $noSistema = $sistema['porChave'][$chave];

            if ($noSistema['valorOriginal'] !== $grupo['principal']) {
                $principalDiferente[] = [
                    'chave' => $chave,
                    'unidade' => $grupo['unidade'],
                    'relatorio' => $grupo['principal'],
                    'sistema' => $noSistema['valorOriginal'],
                    'diferenca' => $grupo['principal'] - $noSistema['valorOriginal'],
                ];

                continue;
            }

            ++$confere;
        }

        $sobra = [];
        $principalSistema = 0;

        foreach ($sistema['porChave'] as $chave => $linha) {
            $principalSistema += $linha['valorOriginal'];

            if (!isset($vistos[$chave])) {
                $sobra[] = [
                    'chave' => $chave,
                    'unidade' => $linha['unidade'],
                    'nn' => $linha['referenciaExterna'],
                    'competencia' => $linha['competencia'],
                ];
            }
        }

        return new ResultadoConferencia(
            pagasMasNaoLiquidadas: $this->obrigacoes->pagasMasNaoLiquidadasDaCarteira($carteira),
            carteira: $carteira->getNome() ?? '?',
            dadosAte: $dadosAte,
            gruposNoRelatorio: count($grupos),
            obrigacoesNoSistema: count($sistema['porChave']),
            confere: $confere,
            faltaNoSistema: $falta,
            sobraNoSistema: $sobra,
            principalDiferente: $principalDiferente,
            ambiguidadeDeCaso: $ambiguidade,
            principalRelatorio: $principalRelatorio,
            principalSistema: $principalSistema,
        );
    }

    /**
     * O lado do SISTEMA, indexado pela mesma chave do relatório.
     *
     * @return array{porChave: array<string, array<string, mixed>>, casosPorUnidade: array<string, list<int>>}
     */
    private function indexarSistema(Carteira $carteira, \DateTimeImmutable $dadosAte): array
    {
        $porChave = [];
        $casosPorUnidade = [];

        foreach ($this->obrigacoes->emAbertoDaCarteiraAte($carteira, $dadosAte) as $linha) {
            [$identificacao] = IdentificacaoDaUnidade::separar($linha['unidade']);

            $chave = AgrupadorDeBoletos::chave(
                $identificacao,
                $linha['referenciaExterna'] ?? '',
                $linha['competencia'],
            );

            $porChave[$chave] = ['unidade' => $identificacao] + $linha;
            $casosPorUnidade[$identificacao][$linha['casoId']] = true;
        }

        return [
            'porChave' => $porChave,
            'casosPorUnidade' => array_map(static fn (array $c): array => array_keys($c), $casosPorUnidade),
        ];
    }

    /**
     * Por que a dívida falta — TRÊS causas, com três consertos diferentes (SPEC §5.5).
     *
     * A primeira versão derivava o motivo do índice de obrigações em aberto, e por isso rotulava
     * "unidade sem obrigação no sistema" um imóvel que tem objeto, caso e 13 obrigações — mandando
     * investigar um cadastro correto. As causas reais são consultadas no cadastro, não inferidas do
     * recorte: são medidos 22 objetos sem caso nenhum em produção.
     *
     * @param array{comCaso: list<string>, semCaso: list<string>} $cadastro
     */
    private function motivoDaFalta(string $unidade, array $cadastro): string
    {
        if (in_array($unidade, $cadastro['semCaso'], true)) {
            return 'objeto existe, mas não tem caso de cobrança';
        }

        if (!in_array($unidade, $cadastro['comCaso'], true)) {
            return 'unidade sem objeto cadastrado na carteira';
        }

        return 'caso existe, esta dívida não';
    }
}
