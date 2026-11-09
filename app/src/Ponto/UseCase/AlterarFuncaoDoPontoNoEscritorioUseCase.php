<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\AlterarFuncaoDoPontoInput;
use App\Ponto\DTO\FuncaoDoPontoOutput;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use Psr\Clock\ClockInterface;

/**
 * Liga ou desliga uma função do Ponto no escritório: a chave "Escritório" da aba Configurações (spec
 * docs/specs/ponto-desenho-0811.md, E7, P67 e seção 7).
 *
 * Quem: quem administra o ponto do escritório (permissão de gerir o ponto, o primário ou o Master).
 * Para quê: decidir o uso da função no próprio escritório, dentro do que o Master liberou.
 * Pré-condições: a permissão no escritório da sessão; a chave é uma função com a camada Escritório; o Master não a
 * desligou para todos (o Global desligado trava a do escritório).
 * Fluxo: confere as três, garante e trava a linha do escritório (o repositório a cria vazia, com os padrões, se ela
 * ainda não existe: dois primeiros salvamentos ao mesmo tempo não duplicam a linha), grava a chave com quem e quando e
 * devolve o estado novo.
 * Recusas (`FuncaoDoPontoRecusadaException`, nada muda): sem permissão (403), chave desconhecida (422) e travada pelo
 * Master (422). As recusas saem ANTES da transação: uma exceção dentro dela fecharia o EntityManager.
 * Religar o que já vale ligado não grava mudança: a linha pode nascer vazia no primeiro salvamento (vale o mesmo que
 * não ter linha), mas a chave, quem e quando não mudam, e a trilha não ganha uma mudança que não mudou.
 * Auditoria: ConfiguracaoDoPonto é Auditavel; o AuditLogSubscriber grava a mudança no flush, no escritório dela. A
 * trilha encadeada do Ponto é da E9.
 */
final class AlterarFuncaoDoPontoNoEscritorioUseCase
{
    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlterarFuncaoDoPontoInput $input): FuncaoDoPontoOutput
    {
        $this->exigirQuePodeMudar($input);
        $this->configuracoes->emTransacao(fn () => $this->gravar($input));
        $desenho = FuncoesDoPonto::COM_CAMADA_ESCRITORIO[$input->chave];

        return new FuncaoDoPontoOutput($input->chave, $desenho['titulo'], $desenho['icone'], $desenho['ajuda'], true, $input->ligada);
    }

    private function exigirQuePodeMudar(AlterarFuncaoDoPontoInput $input): void
    {
        if (!$this->funcoes->podeConfigurar($input->quem, $input->tenant)) {
            throw FuncaoDoPontoRecusadaException::semPermissao();
        }
        if (!$this->funcoes->temCamadaEscritorio($input->chave)) {
            throw FuncaoDoPontoRecusadaException::desconhecida();
        }
        if (!$this->funcoes->liberadaPeloMaster($input->chave)) {
            throw FuncaoDoPontoRecusadaException::travadaPeloMaster();
        }
    }

    private function gravar(AlterarFuncaoDoPontoInput $input): void
    {
        $agora = $this->relogio->now();
        $configuracao = $this->configuracoes->garantidaETravada($input->tenant, $agora);
        if ($configuracao->estaLigada($input->chave) === $input->ligada) {
            return;
        }

        $configuracao->definir($input->chave, $input->ligada, $input->quem, $agora);
        $this->configuracoes->salvar($configuracao, flush: true);
    }
}
