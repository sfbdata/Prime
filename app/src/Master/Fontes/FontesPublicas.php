<?php

declare(strict_types=1);

namespace App\Master\Fontes;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Dados públicos do mapa do Painel do Master (spec perfis-master D8): malha e população do IBGE,
 * Quadro da Advocacia da OAB e a tabela que o Master pode enviar. O servidor busca, guarda o
 * retrato com fonte e data ({@see ArmazemDeFontes}) e serve ao navegador, que nunca chama site
 * externo.
 *
 * Regra de falha: se a fonte não responde, vale o último retrato guardado. Sem retrato nenhum, a
 * malha/população falham com {@see FonteIndisponivel} (a tela mostra a mensagem do desenho) e o
 * Quadro da Advocacia cai na semente declarada de 07/10/2026 ({@see SementeQuadroOab}).
 */
final class FontesPublicas
{
    private const DIAS_IBGE = 30;
    private const CHAVE_OAB = 'oab.quadro';
    private const CHAVE_TABELA = 'tabela.enviada';
    private const CHAVE_POP_UFS = 'ibge.populacao.ufs';
    private const FONTE_IBGE = 'IBGE (servicodados.ibge.gov.br)';

    public function __construct(
        private readonly ArmazemDeFontes $armazem,
        private readonly ClienteIbge $ibge,
        private readonly ClienteQuadroOab $clienteOab,
        private readonly LeitorQuadroOab $leitorOab,
        private readonly ClockInterface $relogio,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * JSON pronto `{"geo": <GeoJSON dos municípios>, "nomes": {codigo: nome}}` da UF.
     *
     * @throws FonteIndisponivel sem retrato e sem IBGE
     */
    public function municipios(string $uf): string
    {
        $codigo = self::codigoUf($uf);

        return (string) $this->obter('ibge.malha.' . $codigo, function () use ($codigo): string {
            $geo = $this->ibge->malhaDosMunicipios($codigo);
            try {
                $nomes = $this->ibge->nomesDosMunicipios($codigo);
            } catch (FonteIndisponivel) {
                // Como no protótipo: sem os nomes, a malha ainda serve (municípios sem rótulo).
                $nomes = [];
            }

            return '{"geo":' . $geo . ',"nomes":' . json_encode((object) $nomes, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) . '}';
        });
    }

    /**
     * População estimada dos municípios da UF (com o que veio na tabela enviada por cima).
     *
     * @return array{ano: string, valores: array<string, int>, nomes: array<string, string>}
     *
     * @throws FonteIndisponivel
     */
    public function populacaoDosMunicipios(string $uf): array
    {
        $codigo = self::codigoUf($uf);
        $dados = (array) $this->obter('ibge.populacao.' . $codigo, fn (): array => $this->ibge->populacao(sprintf('N6[N3[%d]]', $codigo)));

        $enviada = $this->tabelaEnviada();
        foreach (($enviada['mun'][(string) $codigo] ?? []) as $municipio => $populacao) {
            $dados['valores'][(string) $municipio] = (int) $populacao;
        }

        return $dados;
    }

    /**
     * Tudo o que o menu ⋮ e as dicas do mapa precisam: população e advogados por UF (código IBGE),
     * o Quadro da Advocacia completo, fontes e datas.
     *
     * @return array<string, mixed>
     */
    public function resumo(): array
    {
        $populacao = null;
        try {
            $populacao = (array) $this->obter(self::CHAVE_POP_UFS, fn (): array => $this->ibge->populacao('N3[all]'));
        } catch (FonteIndisponivel) {
            // Sem população: o mapa segue sem o número de habitantes (nada estimado).
        }
        $retratoPop = $this->armazem->ler(self::CHAVE_POP_UFS);

        $oab = $this->quadroOab();
        $enviada = $this->tabelaEnviada();
        $retratoTabela = $this->armazem->ler(self::CHAVE_TABELA);
        $tabelaMaisNova = $retratoTabela !== null && (string) $retratoTabela['buscadoEm'] > (string) ($oab['buscadoEm'] ?? '');

        $porUf = [];
        foreach (ClienteIbge::CODIGO_UF as $sigla => $codigo) {
            $c = (string) $codigo;
            $linha = [];
            $pop = $enviada['uf'][$c]['pop'] ?? $populacao['valores'][$c] ?? null;
            if ($pop !== null) {
                $linha['pop'] = (int) $pop;
            }
            $advManual = $enviada['uf'][$c]['adv'] ?? null;
            $adv = $tabelaMaisNova && $advManual !== null ? $advManual : ($oab['uf'][$sigla][0] ?? $advManual);
            if ($adv !== null) {
                $linha['adv'] = (int) $adv;
            }
            $porUf[$c] = $linha;
        }

        return [
            'uf'           => $porUf,
            'anoPop'       => $populacao['ano'] ?? null,
            'fontePop'     => self::FONTE_IBGE,
            'popBuscadaEm' => $retratoPop['buscadoEm'] ?? null,
            'oab'          => ['data' => $oab['data'], 'fonte' => $oab['fonte'], 'uf' => $oab['uf']],
            'fonteAdv'     => ($tabelaMaisNova ? 'tabela enviada + ' : '') . 'OAB ' . $oab['data'],
            'atualizado'   => max((string) ($retratoPop['buscadoEm'] ?? ''), (string) ($oab['buscadoEm'] ?? ''), (string) ($retratoTabela['buscadoEm'] ?? '')) ?: null,
            'urlOab'       => ClienteQuadroOab::URL,
        ];
    }

    /**
     * Último retrato do Quadro da Advocacia (ou a semente declarada).
     *
     * @return array{data: string, fonte: string, uf: array<string, list<int|null>>, buscadoEm: ?string}
     */
    public function quadroOab(): array
    {
        $retrato = $this->armazem->ler(self::CHAVE_OAB);
        if ($retrato !== null && is_array($retrato['dados'])) {
            return [
                'data'      => (string) $retrato['dados']['data'],
                'fonte'     => $retrato['fonte'],
                'uf'        => $retrato['dados']['uf'],
                'buscadoEm' => $retrato['buscadoEm'],
            ];
        }

        return ['data' => SementeQuadroOab::DATA, 'fonte' => SementeQuadroOab::FONTE, 'uf' => SementeQuadroOab::UF, 'buscadoEm' => null];
    }

    /**
     * Busca de novo a população das UFs (IBGE) e o Quadro da Advocacia (OAB). Fonte que falhar
     * mantém o retrato anterior.
     *
     * @return array{ibge: bool, oab: bool}
     */
    public function atualizar(): array
    {
        $ibge = true;
        try {
            $this->armazem->gravar(self::CHAVE_POP_UFS, $this->ibge->populacao('N3[all]'), self::FONTE_IBGE);
        } catch (FonteIndisponivel $e) {
            $ibge = false;
            $this->logger->warning('Painel do Master: população do IBGE não atualizada; mantido o retrato anterior.', ['erro' => $e->getMessage()]);
        }

        $oab = true;
        try {
            $lido = $this->leitorOab->ler($this->clienteOab->baixarPagina());
            $this->armazem->gravar(self::CHAVE_OAB, [
                'data' => $this->relogio->now()->format('d/m/Y'),
                'uf'   => $lido['uf'],
            ], ClienteQuadroOab::URL);
        } catch (FonteIndisponivel $e) {
            $oab = false;
            $this->logger->warning('Painel do Master: Quadro da Advocacia não atualizado; mantido o retrato anterior.', ['erro' => $e->getMessage()]);
        }

        return ['ibge' => $ibge, 'oab' => $oab];
    }

    /**
     * Grava um novo retrato da OAB a partir do CSV do modelo ("Baixar planilha OAB"). Posições
     * vazias no arquivo mantêm o valor do retrato anterior, como no protótipo.
     *
     * @param array<string, list<int|null>> $porUf
     *
     * @return array{data: string, seccionais: int}
     */
    public function gravarQuadroOabEnviado(array $porUf, string $nomeArquivo): array
    {
        $atual = $this->quadroOab()['uf'];
        foreach ($porUf as $sigla => $valores) {
            $base = $atual[$sigla] ?? array_fill(0, 14, 0);
            foreach ($valores as $i => $v) {
                if ($v !== null) {
                    $base[$i] = $v;
                }
            }
            $atual[$sigla] = $base;
        }
        ksort($atual);

        $data = $this->relogio->now()->format('d/m/Y');
        $this->armazem->gravar(self::CHAVE_OAB, ['data' => $data, 'uf' => $atual], 'arquivo ' . $nomeArquivo . ' (cópia do site da OAB)');

        return ['data' => $data, 'seccionais' => count($porUf)];
    }

    /**
     * Junta a tabela enviada (população e advogados por estado; população por município) ao que já
     * foi enviado antes.
     *
     * @param array{uf: array<string, array{pop?: int, adv?: int}>, mun: array<string, array<string, int>>} $tabela
     */
    public function gravarTabelaEnviada(array $tabela, string $nomeArquivo): void
    {
        $atual = $this->tabelaEnviada();
        foreach ($tabela['uf'] as $codigo => $valores) {
            $atual['uf'][$codigo] = array_merge($atual['uf'][$codigo] ?? [], $valores);
        }
        foreach ($tabela['mun'] as $uf => $municipios) {
            $atual['mun'][$uf] = array_replace($atual['mun'][$uf] ?? [], $municipios);
        }

        $this->armazem->gravar(self::CHAVE_TABELA, $atual, 'tabela enviada: ' . $nomeArquivo);
    }

    /**
     * @return array{uf: array<string, array{pop?: int, adv?: int}>, mun: array<string, array<string, int>>}
     */
    private function tabelaEnviada(): array
    {
        $retrato = $this->armazem->ler(self::CHAVE_TABELA);
        $dados = is_array($retrato['dados'] ?? null) ? $retrato['dados'] : [];

        return ['uf' => (array) ($dados['uf'] ?? []), 'mun' => (array) ($dados['mun'] ?? [])];
    }

    private function obter(string $chave, callable $buscar): mixed
    {
        $retrato = $this->armazem->ler($chave);
        if ($this->armazem->fresco($retrato, self::DIAS_IBGE)) {
            return $retrato['dados'];
        }

        try {
            $dados = $buscar();
            $this->armazem->gravar($chave, $dados, self::FONTE_IBGE);

            return $dados;
        } catch (FonteIndisponivel $e) {
            $this->logger->warning('Painel do Master: IBGE indisponível.', ['chave' => $chave, 'erro' => $e->getMessage()]);
            if ($retrato !== null) {
                return $retrato['dados'];
            }

            throw $e;
        }
    }

    /**
     * @throws \InvalidArgumentException UF inexistente (rota com sigla inválida)
     */
    private static function codigoUf(string $uf): int
    {
        $codigo = ClienteIbge::CODIGO_UF[strtoupper($uf)] ?? null;
        if ($codigo === null) {
            throw new \InvalidArgumentException('UF desconhecida: ' . $uf);
        }

        return $codigo;
    }
}
