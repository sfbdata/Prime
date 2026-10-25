<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\DTO\EscritorioNaIaDoMasterOutput;
use App\Inteligencia\DTO\PainelDaIaDoMasterOutput;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\CustoDaIa;

/**
 * O estado da aba "Configurações da I.A." do Master: instalação (chave da API gravada e a trava IA_HABILITADA, só
 * leitura), a chave geral, o modelo, a retenção das respostas e os escritórios ativos, cada um com o consumo do mês
 * (análises e gasto estimado em US$, lidos numa consulta só para todos). Só leitura; quem chama já exigiu o Master.
 *
 * A chave da API entra aqui só para saber se está vazia: o valor nunca sai deste objeto.
 */
final class ObterPainelDaIaDoMasterUseCase
{
    public function __construct(
        private readonly ConfiguracaoDaPlataformaDeIaRepository $plataforma,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly string $chaveDaApi,
        private readonly bool $iaHabilitada,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly CustoDaIa $custo,
    ) {
    }

    public function executar(): PainelDaIaDoMasterOutput
    {
        $plataforma = $this->plataforma->obter();

        return new PainelDaIaDoMasterOutput(
            chaveDaApiGravada: trim($this->chaveDaApi) !== '',
            travaDoServidorLigada: $this->iaHabilitada,
            ligadaParaTodos: $plataforma->isLigadaParaTodos(),
            modelo: $plataforma->modeloEscolhido(),
            mesesDeRetencao: $plataforma->getMesesDeRetencao(),
            escritorios: $this->escritoriosComConsumo(),
        );
    }

    /** @return list<EscritorioNaIaDoMasterOutput> */
    private function escritoriosComConsumo(): array
    {
        $consumo = $this->consumoDoMesPorEscritorio();

        return array_map(static fn (array $linha): EscritorioNaIaDoMasterOutput => EscritorioNaIaDoMasterOutput::daLinha(
            $linha,
            $consumo[$linha['tenant_id']]['analises'] ?? 0,
            $consumo[$linha['tenant_id']]['centavos'] ?? 0,
        ), $this->configuracoes->listarEscritoriosParaOMaster());
    }

    /** @return array<int, array{analises: int, centavos: int}> */
    private function consumoDoMesPorEscritorio(): array
    {
        $porEscritorio = [];
        foreach ($this->analises->consumoDosEscritoriosDesde(new \DateTimeImmutable('first day of this month midnight')) as $linha) {
            $porEscritorio[$linha['tenantId']]['analises'] = ($porEscritorio[$linha['tenantId']]['analises'] ?? 0) + $linha['analises'];
            $porEscritorio[$linha['tenantId']]['linhas'][] = $linha;
        }

        return array_map(fn (array $e): array => [
            'analises' => $e['analises'],
            'centavos' => $this->custo->centavosDoConsumo($e['linhas']),
        ], $porEscritorio);
    }
}
