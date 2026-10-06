<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\UseCase\PurgarLixeiraUseCase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Esvazia a lixeira da aba Documentos (D7): apaga de verdade — linha e arquivo — o que está na
 * lixeira há mais de `--dias` (padrão 30, S-10). Nenhum cron o aciona nesta frente: é do operador.
 *
 * `--dry-run` conta e lista sem apagar nada; `--limite=N` processa no máximo N entradas da fila
 * (seções e documentos vencidos) e a próxima execução retoma de onde parou. A ordem INV-6 e a
 * política de falha moram em `PurgarLixeiraUseCase`: arquivo só sai depois do COMMIT; banco que
 * recusa interrompe sem tocar no disco (código de saída FAILURE); disco que recusa depois do
 * COMMIT vira órfão registrado no log, listado aqui, e não derruba a execução.
 */
#[AsCommand(
    name: 'app:documentos:purgar-lixeira',
    description: 'Apaga de verdade (linha e arquivo) os documentos e subpastas que estão na lixeira há mais de N dias',
)]
final class PurgarLixeiraCommand extends Command
{
    public const DIAS_PADRAO = 30;

    public function __construct(
        private readonly PurgarLixeiraUseCase $purga,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dias', null, InputOption::VALUE_REQUIRED, 'Retenção: só sai o que foi excluído há MAIS de N dias', (string) self::DIAS_PADRAO)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Conta e lista o que sairia, sem apagar nada')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Processar no máximo N entradas da fila nesta execução')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Só a lixeira deste escritório (id do tenant). Sem a opção, a instalação inteira: a retenção é política da instalação, não do escritório');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // `--dias` tem padrão (30), então nunca vem null; a checagem fica pelo tipo do helper.
        $dias     = $this->inteiroPositivoOuNull($input->getOption('dias'), 'dias', $io);
        $limite   = $this->inteiroPositivoOuNull($input->getOption('limite'), 'limite', $io);
        $tenantId = $this->inteiroPositivoOuNull($input->getOption('tenant'), 'tenant', $io);
        if ($dias === false || $dias === null || $limite === false || $tenantId === false) {
            return Command::FAILURE;
        }

        $io->title('Purga da lixeira de documentos');
        if ($dryRun) {
            $io->note('Modo simulação: nada é apagado do banco nem do disco.');
        }
        if ($tenantId !== null) {
            $io->note(sprintf('Só o escritório %d.', $tenantId));
        }

        try {
            $resultado = $this->purga->executar($dias, $dryRun, $limite, $tenantId);
        } catch (\Throwable $e) {
            // Um lote recusado pelo banco: os anteriores já estão consistentes (linhas e arquivos
            // saíram juntos); este ficou inteiro — nenhum arquivo sai antes do COMMIT.
            $io->error(sprintf('A purga parou: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->section($dryRun ? 'Resumo (simulado — nada apagado)' : 'Resumo');
        // Uma linha legível por máquina (log), antes da tabela para humanos.
        $io->text(sprintf(
            'resumo: modo=%s dias=%d tenant=%s corte=%s candidatos_secoes=%d candidatos_documentos=%d secoes_removidas=%d documentos_removidos=%d arquivos_removidos=%d arquivos_nao_removidos=%d',
            $dryRun ? 'simulacao' : 'purga',
            $dias,
            $tenantId === null ? 'todos' : (string) $tenantId,
            $resultado->corte->format('Y-m-d H:i:s'),
            $resultado->secoesCandidatas,
            $resultado->documentosCandidatos,
            $resultado->secoesRemovidas,
            $resultado->documentosRemovidos,
            $resultado->arquivosRemovidos,
            count($resultado->arquivosNaoRemovidos),
        ));
        $verbo = $dryRun ? 'sairiam' : 'saíram';
        $io->table(['Métrica', 'Total'], [
            ['Excluído antes de', $resultado->corte->format('d/m/Y H:i:s')],
            ['Subpastas vencidas na fila', $resultado->secoesCandidatas],
            ['Documentos vencidos na fila', $resultado->documentosCandidatos],
            [sprintf('Subpastas que %s do banco (com a descendência)', $verbo), $resultado->secoesRemovidas],
            [sprintf('Documentos que %s do banco (os de dentro das subpastas inclusive)', $verbo), $resultado->documentosRemovidos],
            ['Arquivos apagados do armazenamento (depois do COMMIT)', $resultado->arquivosRemovidos],
            ['Arquivos que ficaram (órfãos registrados no log)', count($resultado->arquivosNaoRemovidos)],
        ]);

        if ($resultado->arquivosNaoRemovidos !== []) {
            $io->warning('Arquivos que o armazenamento não removeu depois do COMMIT (ver o log):');
            $io->listing($resultado->arquivosNaoRemovidos);
        }

        return Command::SUCCESS;
    }

    /**
     * @return int|null|false null quando a opção não veio; false quando veio inválida (já reportada)
     */
    private function inteiroPositivoOuNull(mixed $valor, string $nome, SymfonyStyle $io): int|null|false
    {
        if ($valor === null) {
            return null;
        }

        if (!is_string($valor) || preg_match('/^[1-9]\d*$/', $valor) !== 1) {
            $io->error(sprintf('--%s precisa ser um inteiro positivo.', $nome));

            return false;
        }

        return (int) $valor;
    }
}
