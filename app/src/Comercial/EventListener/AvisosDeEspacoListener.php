<?php

declare(strict_types=1);

namespace App\Comercial\EventListener;

use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Repository\AvisoDeEspacoRepository;
use App\Comercial\Service\DisparadorDeAutomacao;
use App\Contribua\Event\EnvioRecusadoPorEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Event\EspacoExtraFoiSolicitado;
use App\Contribua\Event\EspacoFoiPedido;
use App\Contribua\Event\OpcoesDeEspacoForamLiberadas;
use App\Contribua\Event\PedidoDeEspacoFoiRecusado;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Os avisos de espaço aos primários ativos do escritório (spec comercial-e-emails.md §4): `e90` ao cruzar o limite
 * escolhido, `e100` no bloqueio ligado (pela faixa ou pela primeira recusa), `ped` no pedido de mais espaço, `lib` na liberação
 * das opções de espaço extra pelo Master e `resp` na recusa do pedido (os dois sem chave de liga/desliga). Um por
 * escritório e dia (o `ped`, por plano também), reservado em `comercial_aviso_espaco`. Nunca derruba o fluxo de quem
 * gravou: toda exceção vai ao log só com ids e a chave.
 *
 * A recusa sai na hora, antes da exceção. Quando ela chega dentro de uma transação (a cópia de documentos da pasta),
 * o rollback levaria junto a reserva e o envio; por isso fica guardada e sai no fim da requisição, do comando ou da
 * mensagem do worker, com ou sem erro, porque a recusa é um fato.
 */
final class AvisosDeEspacoListener implements ResetInterface
{
    private const FUSO = 'America/Sao_Paulo';

    /** @var array<int, true> escritórios com recusa à espera do fim da transação */
    private array $recusasAdiadas = [];

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly AvisoDeEspacoRepository $avisos,
        private readonly DisparadorDeAutomacao $disparador,
        private readonly TenantRepository $tenants,
        private readonly UserTenantRepository $vinculos,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly Connection $conexao,
    ) {
    }

    #[AsEventListener]
    public function aoSubirOUso(UsoDoEspacoSubiu $evento): void
    {
        $limite = (int) $this->automacoes->estado(Automacao::E90)->atraso;
        if ($limite > 0 && $evento->cruzou($limite)) {
            $this->avisar(Automacao::E90, $evento->tenantId, '', ['limite' => (string) $limite]);
        }
    }

    #[AsEventListener]
    public function aoAtingirFaixa(EspacoAtingiuFaixa $evento): void
    {
        // Sem o bloqueio ligado, "novos envios pausados" seria falso (decisão da f6).
        if ($evento->faixa === FaixaDoEspaco::Bloqueio && $evento->retrato->bloqueioAtivo) {
            $this->avisar(Automacao::E100, $evento->tenantId, '');
        }
    }

    #[AsEventListener]
    public function aoRecusarEnvio(EnvioRecusadoPorEspaco $evento): void
    {
        if ($this->conexao->isTransactionActive()) {
            $this->recusasAdiadas[$evento->tenantId] = true;

            return;
        }
        $this->avisar(Automacao::E100, $evento->tenantId, '');
    }

    #[AsEventListener]
    public function aoPedirEspaco(EspacoFoiPedido $evento): void
    {
        $this->avisar(Automacao::Ped, $evento->tenantId, $evento->codigoDoPlano);
    }

    /** O pedido do desenho novo (Contribua 14 e 15/11): a mesma confirmação `ped`, uma por escritório e dia. */
    #[AsEventListener]
    public function aoSolicitarEspacoExtra(EspacoExtraFoiSolicitado $evento): void
    {
        $this->avisar(Automacao::Ped, $evento->tenantId, 'extra');
    }

    /**
     * Só quando o Master libera (o desenho 14 e 15/11 só enfileira o e-mail na decisão dele). A liberação automática
     * (97%) já aparece na tela e no aviso de espaço: não manda este e-mail.
     */
    #[AsEventListener]
    public function aoLiberarOpcoes(OpcoesDeEspacoForamLiberadas $evento): void
    {
        if ($evento->origem === OrigemDaLiberacao::Master) {
            $this->avisar(Automacao::Lib, $evento->tenantId, $evento->origem->value);
        }
    }

    #[AsEventListener]
    public function aoRecusarPedido(PedidoDeEspacoFoiRecusado $evento): void
    {
        $this->avisar(Automacao::Resp, $evento->tenantId, 'recusa');
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function aoTerminar(): void
    {
        $adiadas = array_keys($this->recusasAdiadas);
        $this->reset();
        foreach ($adiadas as $tenantId) {
            $this->avisar(Automacao::E100, $tenantId, '');
        }
    }

    public function reset(): void
    {
        $this->recusasAdiadas = [];
    }

    /**
     * Reserva a vez e manda a cada primário, numa transação própria: se o envio falhar, a reserva volta e o aviso
     * pode sair no próximo evento. Desligada, não consome a vez.
     *
     * @param array<string, string> $variaveis
     */
    private function avisar(Automacao $automacao, int $tenantId, string $plano, array $variaveis = []): void
    {
        try {
            if (!$this->disparador->estaLigada($automacao)) {
                return;
            }
            $this->conexao->transactional(function () use ($automacao, $tenantId, $plano, $variaveis): void {
                $agora = $this->clock->now();
                $dia = $agora->setTimezone(new \DateTimeZone(self::FUSO));
                if (!$this->avisos->reservar($tenantId, $automacao, $dia, $plano, $agora)) {
                    return;
                }
                foreach ($this->primariosDe($tenantId) as $primario) {
                    $this->disparador->disparar($automacao, (string) $primario->getEmail(), $primario->getFullName(), $variaveis);
                }
            });
        } catch (\Throwable $erro) {
            $this->logger->error('Comercial: aviso de espaço falhou sem afetar quem gravou.', [
                'automacao' => $automacao->value,
                'tenantId' => $tenantId,
                'erro' => $erro::class,
            ]);
        }
    }

    /** @return list<User> os primários ativos com e-mail, um por pessoa */
    private function primariosDe(int $tenantId): array
    {
        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant) {
            return [];
        }
        $primarios = [];
        foreach ($this->vinculos->findAtivosComPerfilPorTenant($tenant) as $vinculo) {
            $user = $vinculo->getUser();
            if ($this->regraDosPrimarios->ehPrimario($vinculo) && $user->getEmail() !== null) {
                $primarios[(int) $user->getId()] = $user;
            }
        }

        return array_values($primarios);
    }
}
