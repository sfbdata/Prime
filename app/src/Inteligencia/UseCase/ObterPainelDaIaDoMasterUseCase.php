<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Inteligencia\DTO\EscritorioNaIaDoMasterOutput;
use App\Inteligencia\DTO\PainelDaIaDoMasterOutput;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;

/**
 * O estado da aba "Configurações da I.A." do Master: instalação (chave da API gravada e a trava IA_HABILITADA, só
 * leitura), a chave geral, o modelo, a retenção das respostas e os escritórios ativos. Só leitura; quem chama já exigiu o Master.
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
            escritorios: array_map(EscritorioNaIaDoMasterOutput::daLinha(...), $this->configuracoes->listarEscritoriosParaOMaster()),
        );
    }
}
