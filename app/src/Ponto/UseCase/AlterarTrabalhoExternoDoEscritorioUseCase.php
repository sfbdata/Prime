<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\AlterarTrabalhoExternoInput;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use Psr\Clock\ClockInterface;

/**
 * Permite ou não o trabalho externo no escritório: a caixa "Permitir trabalho externo, com motivo e análise" da aba
 * Configurações (spec docs/specs/ponto-desenho-0811.md, E12 e P74).
 *
 * Quem: quem administra o ponto do escritório (permissão de gerir o ponto, o primário ou o Master), como a chave
 * "Escritório" das funções (E7).
 * Para quê: decidir se a batida fora do raio das sedes pode entrar como trabalho externo, com o local e o motivo e a
 * análise do gestor. Sem ela, a batida fora do raio continua recusada.
 * Pré-condição: a permissão no escritório da sessão. Não há camada Global do Master: o ajuste é só do escritório.
 * Fluxo: confere a permissão, garante e trava a linha do escritório (o repositório a cria com os padrões se ela ainda
 * não existe: dois primeiros salvamentos ao mesmo tempo não duplicam a linha), grava o ajuste com quem e quando e
 * devolve o valor novo. Repetir o valor que já vale não grava mudança.
 * Recusa (`FuncaoDoPontoRecusadaException`, nada muda): sem permissão (403), antes da transação.
 * Auditoria: ConfiguracaoDoPonto é Auditavel; o AuditLogSubscriber grava a mudança no flush, no escritório dela. A
 * mudança de configuração não entra na trilha encadeada do Ponto (como a chave das funções, E7).
 */
final class AlterarTrabalhoExternoDoEscritorioUseCase
{
    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(AlterarTrabalhoExternoInput $input): bool
    {
        if (!$this->funcoes->podeConfigurar($input->quem, $input->tenant)) {
            throw FuncaoDoPontoRecusadaException::semPermissao();
        }
        $this->configuracoes->emTransacao(fn () => $this->gravar($input));

        return $input->permitir;
    }

    private function gravar(AlterarTrabalhoExternoInput $input): void
    {
        $agora = $this->relogio->now();
        $configuracao = $this->configuracoes->garantidaETravada($input->tenant, $agora);
        if ($configuracao->permiteTrabalhoExterno() === $input->permitir) {
            return;
        }

        $configuracao->definirTrabalhoExterno($input->permitir, $input->quem, $agora);
        $this->configuracoes->salvar($configuracao, flush: true);
    }
}
