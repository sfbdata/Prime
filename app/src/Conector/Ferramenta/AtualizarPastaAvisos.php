<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Pasta\DTO\PedirAcessoInput;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Pasta\UseCase\PedirAcessoAPastaUseCase;

/**
 * Os avisos que a `atualizar_pasta` dispara (`docs/specs/mcp-remoto-oauth.md`, 6.6). Os dois acrescentam e são diretos:
 *
 *  - `pedir_acesso` (`nivel?`, padrão editar; `motivo?`): o pedido ao primário numa pasta com cadeado
 *    ({@see PedirAcessoAPastaUseCase}, que avisa os primários). Basta ver a pasta; pasta que o cadeado esconde da pessoa
 *    já é "não encontrado" pela guarda (um pedido aprovado nunca fura o cadeado, 6.2, regra 3);
 *  - `enviar_relatorio_sem_processo`: o relatório das pastas sem processo para a Controladoria, os primários e cada
 *    responsável ({@see EnviarRelatorioSemProcessoUseCase}), a partir de uma pasta que a pessoa edita, como o menu da
 *    pasta (`expediente_relatorio_processos_enviar`). O relatório é do escritório inteiro, não só desta pasta.
 */
final class AtualizarPastaAvisos implements AtualizarPastaGrupo
{
    public const SEM_CADEADO = 'Esta pasta não tem cadeado: não há acesso a pedir.';
    public const JA_TEM_ACESSO = 'Você já tem esse acesso a esta pasta.';
    public const RECUSA_PEDIDO = 'O BlueJus recusou o pedido de acesso. Só se pede de novo 24 horas depois do pedido anterior.';
    public const RELATORIO_HA_POUCO = 'O relatório já foi enviado há pouco. Tente de novo em alguns minutos.';
    public const RECUSA_RELATORIO = 'O BlueJus recusou enviar o relatório. Tente de novo pela tela.';

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AcessoAPasta $cadeado,
        private readonly PedirAcessoAPastaUseCase $pedirAcesso,
        private readonly EnviarRelatorioSemProcessoUseCase $enviarRelatorio,
    ) {
    }

    public function acoes(): array
    {
        return ['pedir_acesso', 'enviar_relatorio_sem_processo'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $editar = $entrada->acao === 'enviar_relatorio_sem_processo';
        $pasta = $this->acesso->viva($chamada, $entrada->acao, $entrada->pastaId(), $editar);
        $dados = $editar ? $this->relatorio($chamada) : $this->pedido($pasta, $entrada, $chamada);
        $id = (int) $pasta->getId();

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => $entrada->acao, 'pasta_id' => $id] + $dados, ids: ['pasta' => [$id]]);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function pedido(Pasta $pasta, AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $nivel = NivelDeAcesso::from($entrada->texto('nivel') ?? NivelDeAcesso::Editar->value);
        if ($this->cadeado->cadeadoDe($pasta) === null) {
            throw ErroDaFerramenta::recusada(self::SEM_CADEADO);
        }
        $atual = $this->cadeado->nivel($contexto->pessoa, $pasta);
        if ($atual === NivelDeAcesso::Editar || $atual === $nivel) {
            throw ErroDaFerramenta::recusada(self::JA_TEM_ACESSO);
        }
        $motivo = $entrada->texto('motivo');
        $motivo = $motivo === null || trim($motivo) === '' ? null : trim($motivo);
        $input = new PedirAcessoInput($pasta, $contexto->pessoa, $contexto->escritorio, $nivel, $motivo);
        $pedido = AtualizarPastaRecusa::gravando(fn () => $this->pedirAcesso->executar($input), self::RECUSA_PEDIDO);

        return ['nivel_pedido' => $pedido->nivelPedido, 'primarios_avisados' => $pedido->avisados];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function relatorio(ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $resultado = AtualizarPastaRecusa::gravando(fn () => $this->enviarRelatorio->executar($contexto->escritorio, $contexto->pessoa, false), self::RECUSA_RELATORIO);
        if ($resultado->acimaDoTeto) {
            throw ErroDaFerramenta::recusada(self::RELATORIO_HA_POUCO);
        }
        if (!$resultado->enviado) {
            throw ErroDaFerramenta::recusada(self::RECUSA_RELATORIO);
        }

        return ['avisados' => $resultado->avisados, 'sem_controladoria' => $resultado->semControladoria, 'aviso' => 'O relatório é do escritório inteiro, não só desta pasta.'];
    }
}
