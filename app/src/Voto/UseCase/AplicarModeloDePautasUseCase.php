<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Repository\PautaDaAssembleiaRepository;
use App\Voto\Service\ModelosDePautas;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\Service\TransacaoDoVoto;

/**
 * "Modelos prontos" do passo Cargos e pautas (desenho 18, L262): cria, no fim da ordem, as pautas do modelo escolhido com
 * a mesma montagem de "adicionar pauta" (AdicionarPautaUseCase::montar: escritório, rascunho, validação das opções e
 * abstenção automática). Tudo ou nada: uma trava de configuração e uma transação para o modelo inteiro, e a recusa de
 * qualquer pauta não grava nenhuma. Devolve os títulos para o aviso "Modelo aplicado: ...". Modelo desconhecido é
 * recusado antes de qualquer coisa.
 */
final class AplicarModeloDePautasUseCase
{
    public function __construct(
        private readonly AdicionarPautaUseCase $adicionar,
        private readonly PautaDaAssembleiaRepository $pautas,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly TransacaoDoVoto $transacao,
    ) {
    }

    /** @return list<string> os títulos das pautas criadas */
    public function executar(Tenant $tenant, int $assembleiaId, string $modelo): array
    {
        $inputs = ModelosDePautas::pautasDoModelo($modelo);

        return $this->serializacao->executar($tenant, $assembleiaId, fn (): array => $this->transacao->executar(function () use ($tenant, $assembleiaId, $inputs): array {
            $titulos = [];
            foreach ($inputs as $input) {
                $this->pautas->salvar($this->adicionar->montar($tenant, $assembleiaId, $input));
                $titulos[] = $input->titulo;
            }

            return $titulos;
        }));
    }
}
