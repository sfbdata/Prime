<?php

declare(strict_types=1);

namespace App\Pagamento\Command;

use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\Service\AvisoAoMaster;
use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Entity\PagamentoEvento;
use App\Pagamento\Enum\ResultadoDaReconsulta;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\Repository\PagamentoEventoRepository;
use App\Pagamento\Repository\PagamentoRepository;
use App\Pagamento\UseCase\ConfirmarPagamentoPeloBancoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Conciliação do registro único de pagamentos (spec `gateway-m0b-webhook-conciliacao.md` §3.3), a cada 15 minutos
 * no Scheduler: rede de segurança do aviso perdido.
 *
 * 1. reconsulta no banco as cobranças `aguardando` com mais de 2 minutos de vida e vencidas há menos de 7 dias;
 * 2. só depois da reconsulta expira as vencidas (um pagamento no último minuto não vira `expirada`);
 * 3. as vencidas há mais de 7 dias só expiram, sem consultar o banco;
 * 4. uma vez por dia, avisa o Master dos pagamentos divergentes que ainda esperam decisão.
 * Sem integração conectada ou com a conciliação desligada, não consulta o banco (as cobranças só expiram quando
 * ninguém mais pode pagá-las pelo banco: aqui, não expira nada, para não vencer cobrança que o banco ainda aceita).
 */
#[AsCommand(name: 'app:pagamento:conciliar', description: 'Confere no banco as cobranças do registro de pagamentos, expira as vencidas e resume as divergentes')]
final class ConciliarPagamentosCommand extends Command
{
    private const LOTE = 200;
    private const CHAVE_RESUMO = 'pagamento_resumo_de_divergentes_';

    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly PagamentoRepository $pagamentos,
        private readonly PagamentoEventoRepository $eventos,
        private readonly ConfirmarPagamentoPeloBancoUseCase $reconsultar,
        private readonly EntityManagerInterface $em,
        private readonly AvisoAoMaster $avisoAoMaster,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $integracao = $this->integracoes->atual();
        if (!$integracao->temCertificado() || '' === (string) $integracao->getClientId() || !($integracao->getRecursos()['conc'] ?? false)) {
            $io->writeln('Nada a fazer: integração desligada ou conciliação automática desligada.');

            return Command::SUCCESS;
        }

        $agora = new \DateTimeImmutable();
        $contagem = [];
        $falhas = 0;
        foreach ($this->pagamentos->aguardandoParaConciliar($agora, new \DateInterval('PT2M'), new \DateInterval('P7D'), self::LOTE) as $pagamento) {
            try {
                $resultado = $this->reconsultar->executar($pagamento->getProvedor(), (string) $pagamento->getIdExterno());
                $contagem[$resultado->value] = ($contagem[$resultado->value] ?? 0) + 1;
            } catch (FalhaNoBanco|\DomainException $e) {
                ++$falhas;
                $io->warning('Pagamento ' . $pagamento->getReferencia() . ': ' . $e->getMessage());
            }
            if (!$this->em->isOpen()) {
                // Uma confirmação perdeu a corrida para o webhook e fechou o gerenciador: o resto fica para a próxima volta.
                $io->writeln('Conciliação interrompida por confirmação simultânea; o restante segue na próxima volta.');

                return Command::FAILURE;
            }
        }

        $expiradas = $this->expirar($agora, $falhas > 0);
        $this->resumirDivergentes($agora);
        $io->writeln(sprintf(
            'Conciliação: %d confirmado(s), %d divergente(s), %d cancelado(s), %d expirado(s), %d falha(s).',
            $contagem[ResultadoDaReconsulta::Confirmado->value] ?? 0,
            $contagem[ResultadoDaReconsulta::Divergente->value] ?? 0,
            $contagem[ResultadoDaReconsulta::Cancelado->value] ?? 0,
            $expiradas,
            $falhas,
        ));

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Expira as vencidas. Com falha do banco nesta volta, só expira as que não têm id no banco (nunca chegaram a ele) ou
     * que venceram há mais de 7 dias: a reconsulta não pôde conferir as outras.
     */
    private function expirar(\DateTimeImmutable $agora, bool $bancoFalhou): int
    {
        $limiteAntigo = $agora->sub(new \DateInterval('P7D'));
        $total = 0;
        foreach ($this->pagamentos->vencidasAguardando($agora, self::LOTE) as $pagamento) {
            $naoConsultavel = $pagamento->getIdExterno() === null || ($pagamento->getExpiraEm() !== null && $pagamento->getExpiraEm() < $limiteAntigo);
            if ($bancoFalhou && !$naoConsultavel) {
                continue;
            }
            $total += $this->expirarUm($pagamento, $agora) ? 1 : 0;
        }

        return $total;
    }

    private function expirarUm(Pagamento $pagamento, \DateTimeImmutable $agora): bool
    {
        return (bool) $this->em->wrapInTransaction(function () use ($pagamento, $agora): bool {
            $this->pagamentos->travar($pagamento);
            if (!$pagamento->expirar($agora)) {
                return false;
            }
            $this->eventos->salvar(new PagamentoEvento($pagamento->getTenant(), $pagamento, 'expirado', 'sistema', 'Cobrança vencida sem pagamento.'));

            return true;
        });
    }

    /** Um aviso por dia, enquanto houver divergente sem decisão: o Master não esquece dinheiro parado. */
    private function resumirDivergentes(\DateTimeImmutable $agora): void
    {
        $item = $this->cache->getItem(self::CHAVE_RESUMO . $agora->format('Ymd'));
        if ($item->isHit()) {
            return;
        }
        $abertos = array_values(array_filter(
            $this->pagamentos->divergentesAbertos(self::LOTE),
            static fn (Pagamento $p): bool => $p->getStatus() === StatusDoPagamento::Divergente,
        ));
        if ($abertos === []) {
            return;
        }
        $this->avisoAoMaster->avisar(
            $abertos[0]->getTenant(),
            AvisoAoMaster::TIPO_PAGAMENTO_DIVERGENTE,
            sprintf('%d pagamento(s) divergente(s) esperam a decisão do Master.', count($abertos)),
            $this->urls->generate('central_index'),
        );
        $item->set(true)->expiresAfter(86400);
        $this->cache->save($item);
    }
}
