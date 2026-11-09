<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\AlterarFuncaoDoPontoInput;
use App\Ponto\DTO\FuncaoDoPontoOutput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
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
 * Fluxo: confere as três, trava a linha do escritório, grava a chave com quem e quando e devolve o estado novo.
 * Recusas (`FuncaoDoPontoRecusadaException`, nada muda): sem permissão (403), chave desconhecida (422) e travada pelo
 * Master (422). As recusas saem ANTES da transação: uma exceção dentro dela fecharia o EntityManager.
 * Religar o que já vale ligado (ou sem linha, que já é ligada) não grava nada: a linha só nasce no primeiro
 * salvamento que muda algo, e a trilha não ganha uma mudança que não mudou.
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
        $configuracao = $this->configuracoes->doTenantTravado($input->tenant);
        if (($configuracao?->estaLigada($input->chave) ?? true) === $input->ligada) {
            return;
        }

        $configuracao ??= new ConfiguracaoDoPonto($input->tenant);
        $configuracao->definir($input->chave, $input->ligada, $input->quem, $this->relogio->now());
        $this->configuracoes->salvar($configuracao, flush: true);
    }
}
