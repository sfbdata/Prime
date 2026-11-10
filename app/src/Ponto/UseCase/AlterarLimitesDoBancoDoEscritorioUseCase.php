<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\DTO\AlterarLimitesDoBancoInput;
use App\Ponto\DTO\LimitesDoBancoOutput;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Exception\FuncaoDoPontoRecusadaException;
use App\Ponto\Exception\LimitesDoBancoRecusadosException;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\FuncoesDoPonto;
use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use Psr\Clock\ClockInterface;

/**
 * Muda o limite e/ou o prazo do banco de horas do escritório: os campos "Limite do banco (horas)" e "Prazo de
 * compensação (meses)" da aba Configurações (spec docs/specs/ponto-desenho-0811.md, E17 e P73; desenho 16 L250 e L251).
 *
 * Quem: quem administra o ponto do escritório (permissão de gerir o ponto, o primário ou o Master), como a caixa do
 * trabalho externo (E12) e a chave "Escritório" das funções (E7).
 * Para quê: o limite acende o aviso de banco acima do limite (P52); o prazo dá o vencimento de cada lote (P51 a).
 * Pré-condições: a permissão no escritório da sessão; limite de 1 a 9999 horas; prazo de 1 a 24 meses (a mesma faixa
 * que a leitura confere em {@see LimitesDoBancoDeHorasDoEscritorio}). O campo do limite aceita 0 no navegador, como o
 * desenho (`min="0"`), mas o servidor recusa: um limite zero acenderia o aviso para todo mundo.
 * Fluxo: confere a permissão e a faixa, garante e trava a linha do escritório (o repositório a cria com os padrões se
 * ela ainda não existe), grava o que veio no pedido (o que não veio fica como está) com quem e quando e devolve o que
 * ficou gravado. Repetir o valor que já vale não grava mudança.
 * Recusas (nada muda, antes da transação: uma exceção dentro dela fecharia o EntityManager): sem permissão
 * (`FuncaoDoPontoRecusadaException`, 403) e fora da faixa (`LimitesDoBancoRecusadosException`, 422).
 * Auditoria: ConfiguracaoDoPonto é Auditavel; o AuditLogSubscriber grava a mudança no flush, no escritório dela. O
 * limite e o prazo não entram na trilha encadeada do Ponto (D5: ficam só no `audit_log`).
 * Depois de gravar, a leitura do limite e do prazo esquece o que já tinha lido neste pedido: sem isso, quem leu antes
 * (sem a linha, quando ela ainda não existia) continuaria com o padrão até o pedido acabar.
 */
final class AlterarLimitesDoBancoDoEscritorioUseCase
{
    public const LIMITE_MINIMO_HORAS = 1;
    public const LIMITE_MAXIMO_HORAS = 9999;

    public function __construct(
        private readonly FuncoesDoPonto $funcoes,
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
        private readonly ClockInterface $relogio,
        private readonly LimitesDoBancoDeHorasDoEscritorio $limites,
    ) {
    }

    public function executar(AlterarLimitesDoBancoInput $input): LimitesDoBancoOutput
    {
        if (!$this->funcoes->podeConfigurar($input->quem, $input->tenant)) {
            throw FuncaoDoPontoRecusadaException::semPermissao();
        }
        $this->exigirNaFaixa($input);

        $gravada = $this->configuracoes->emTransacao(fn (): ConfiguracaoDoPonto => $this->gravar($input));
        $this->limites->reset();

        return new LimitesDoBancoOutput($gravada->getBancoLimiteHoras(), $gravada->getBancoPrazoMeses());
    }

    private function exigirNaFaixa(AlterarLimitesDoBancoInput $input): void
    {
        if ($input->limiteHoras !== null && $input->limiteHoras < self::LIMITE_MINIMO_HORAS) {
            throw LimitesDoBancoRecusadosException::limiteAbaixoDoMinimo();
        }
        if ($input->limiteHoras !== null && $input->limiteHoras > self::LIMITE_MAXIMO_HORAS) {
            throw LimitesDoBancoRecusadosException::limiteAcimaDoMaximo();
        }
        $prazo = $input->prazoMeses;
        if ($prazo !== null && ($prazo < LimitesDoBancoDeHorasDoEscritorio::PRAZO_MINIMO_MESES || $prazo > LimitesDoBancoDeHorasDoEscritorio::PRAZO_MAXIMO_MESES)) {
            throw LimitesDoBancoRecusadosException::prazoForaDaFaixa();
        }
    }

    private function gravar(AlterarLimitesDoBancoInput $input): ConfiguracaoDoPonto
    {
        $agora = $this->relogio->now();
        $configuracao = $this->configuracoes->garantidaETravada($input->tenant, $agora);
        $limite = $input->limiteHoras ?? $configuracao->getBancoLimiteHoras();
        $prazo = $input->prazoMeses ?? $configuracao->getBancoPrazoMeses();
        if ($limite === $configuracao->getBancoLimiteHoras() && $prazo === $configuracao->getBancoPrazoMeses()) {
            return $configuracao;
        }

        $configuracao->definirLimitesDoBanco($limite, $prazo, $input->quem, $agora);
        $this->configuracoes->salvar($configuracao, flush: true);

        return $configuracao;
    }
}
