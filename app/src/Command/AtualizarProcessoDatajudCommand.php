<?php

namespace App\Command;

use App\Entity\Processo;
use App\Repository\ContratoRepository;
use App\Repository\ProcessoRepository;
use App\Service\DatajudClient;
use App\Service\DatajudProcessoMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:datajud:atualizar-processo',
    description: 'Atualiza um processo via API Publica do Datajud',
)]
class AtualizarProcessoDatajudCommand extends Command
{
    private DatajudClient $client;
    private DatajudProcessoMapper $mapper;
    private ContratoRepository $contratoRepository;
    private ProcessoRepository $processoRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        DatajudClient $client,
        DatajudProcessoMapper $mapper,
        ContratoRepository $contratoRepository,
        ProcessoRepository $processoRepository,
        EntityManagerInterface $entityManager
    ) {
        parent::__construct();
        $this->client = $client;
        $this->mapper = $mapper;
        $this->contratoRepository = $contratoRepository;
        $this->processoRepository = $processoRepository;
        $this->entityManager = $entityManager;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('contratoId', InputArgument::REQUIRED, 'ID do contrato')
            ->addArgument('tribunalAlias', InputArgument::REQUIRED, 'Alias do tribunal (ex: api_publica_tjsp)')
            ->addArgument('numeroProcesso', InputArgument::REQUIRED, 'Numero do processo (CNJ)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $contratoId = (int) $input->getArgument('contratoId');
        $tribunalAlias = (string) $input->getArgument('tribunalAlias');
        $numeroProcesso = (string) $input->getArgument('numeroProcesso');

        $contrato = $this->contratoRepository->find($contratoId);
        if (!$contrato) {
            $output->writeln('<error>Contrato nao encontrado.</error>');
            return Command::FAILURE;
        }

        $response = $this->client->searchByNumeroProcesso($tribunalAlias, $numeroProcesso);
        $hits = $response['hits']['hits'] ?? [];

        if ($hits === []) {
            $output->writeln('<comment>Nenhum processo encontrado no Datajud.</comment>');
            return Command::SUCCESS;
        }

        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? null;
            if (!is_array($source)) {
                continue;
            }

            $numero = (string) ($source['numeroProcesso'] ?? $numeroProcesso);
            $processo = $this->processoRepository->findOneBy(['numeroProcesso' => $numero]);

            if (!$processo) {
                $processo = new Processo();
                $processo->setNumeroProcesso($numero);
            }

            $processo->setContrato($contrato);
            $this->mapper->mapFromSource($processo, $source);

            $this->entityManager->persist($processo);
        }

        $this->entityManager->flush();
        $output->writeln('<info>Processo atualizado com sucesso.</info>');

        return Command::SUCCESS;
    }
}
