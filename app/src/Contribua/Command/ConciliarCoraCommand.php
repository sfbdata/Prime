<?php

declare(strict_types=1);

namespace App\Contribua\Command;

use App\Contribua\Banco\FalhaNoBanco;
use App\Contribua\Repository\CobrancaPixDaContaRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Contribua\UseCase\ConfirmarCobrancaPeloBancoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Conciliação (desenho 08, "Conciliação automática"): reconsulta as cobranças abertas dos últimos 30 dias,
 * para o aviso que se perdeu. Cron do dono a cada 15 minutos (C5). Só roda com o recurso ligado.
 */
#[AsCommand(name: 'app:cora:conciliar', description: 'Confere na Cora as cobranças PIX abertas e confirma os apoios pagos')]
final class ConciliarCoraCommand extends Command
{
    private const DIAS = 30;

    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
        private readonly CobrancaPixDaContaRepository $cobrancas,
        private readonly ConfirmarCobrancaPeloBancoUseCase $confirmar,
        private readonly EntityManagerInterface $em,
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
        [$confirmados, $falhas] = [0, 0];
        foreach ($this->cobrancas->abertasDesde('cora', $integracao->getAmbiente(), new \DateTimeImmutable('-' . self::DIAS . ' days')) as $cobranca) {
            try {
                $confirmados += $this->confirmar->executar('cora', (string) $cobranca->getIdNoBanco()) ? 1 : 0;
            } catch (FalhaNoBanco|\DomainException $e) {
                ++$falhas;
                $io->warning('Cobrança ' . $cobranca->getCodigo() . ': ' . $e->getMessage());
            }
            if (!$this->em->isOpen()) {
                // Uma confirmação perdeu a corrida para o webhook e fechou o gerenciador: o resto fica para a próxima volta.
                $io->writeln('Conciliação interrompida por confirmação simultânea; o restante segue na próxima volta.');
                break;
            }
        }
        $io->writeln(sprintf('Conciliação: %d apoio(s) confirmado(s), %d falha(s).', $confirmados, $falhas));

        return $falhas > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
