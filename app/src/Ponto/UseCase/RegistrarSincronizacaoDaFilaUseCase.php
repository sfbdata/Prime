<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\SincronizacaoDaFilaInput;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\AcaoDaTrilhaDoPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\Service\TrilhaDoPonto;

/**
 * Grava na trilha do Ponto o evento "Sincronização" de uma rodada da fila sem internet (spec
 * docs/specs/ponto-desenho-0811.md, E14 e E9; desenho 16, L438: "N batida(s) enviada(s) ao voltar a internet").
 *
 * Quem: a própria pessoa, pela tela do Ponto, logo depois que a rodada da fila enviou batidas.
 * Para quê: a aba Auditoria mostra quando as batidas guardadas chegaram, além da marca " · sem internet" de cada uma.
 * Pré-condições: a sessão com acesso ao Ponto no escritório (o controller confere); os identificadores de envio da
 * rodada.
 * Fluxo: acha as batidas da pessoa NESTE escritório que vieram da fila com esses identificadores; trava a trilha do
 * escritório; tira as que já entraram numa sincronização; grava um evento com as que sobraram, com a pessoa como
 * autora; devolve quantas entraram.
 * Sem evento (devolve 0): nenhum identificador é de uma batida dela da fila (de outra pessoa, de outro escritório, de
 * uma batida comum ou inexistente), ou todas já estão numa sincronização (o mesmo aviso repetido). A tela é só quem
 * avisa: o que conta é o que o servidor acha.
 * Transação: a conferência das repetidas e a gravação rodam juntas, depois da trava da trilha (a mesma que o
 * `registrar` pega, e que segura até o COMMIT): dois avisos iguais ao mesmo tempo gravam um evento só.
 */
final class RegistrarSincronizacaoDaFilaUseCase
{
    public function __construct(
        private readonly RegistroPontoRepository $registros,
        private readonly TrilhaDoPontoRepository $repositorioDaTrilha,
        private readonly TrilhaDoPonto $trilha,
    ) {
    }

    public function executar(SincronizacaoDaFilaInput $input): int
    {
        $tenantId = $input->tenant->getId();
        $batidas = $this->registros->semInternetDosEnvios($input->quem, $input->tenant, $input->idsEnvio);
        if ($tenantId === null || $batidas === []) {
            return 0;
        }
        $ids = array_map(static fn (RegistroPonto $batida): int => (int) $batida->getId(), $batidas);

        return $this->trilha->emTransacao(function () use ($input, $tenantId, $ids): int {
            $this->repositorioDaTrilha->travar($tenantId);
            $novas = array_values(array_diff($ids, $this->repositorioDaTrilha->jaSincronizados($tenantId, $ids)));
            if ($novas === []) {
                return 0;
            }
            $this->trilha->registrar($input->tenant, $input->quem, AcaoDaTrilhaDoPonto::Sincronizacao->value, DetalheDaTrilhaDoPonto::daSincronizacao($input->quem, $novas));

            return \count($novas);
        });
    }
}
