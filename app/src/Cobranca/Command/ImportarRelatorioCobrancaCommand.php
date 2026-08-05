<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use App\Cobranca\UseCase\ImportarRelatorioCarteiraUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa um relatório TOPLIFE (.xlsx) numa Carteira de Cobrança pela LINHA DE COMANDO — o caminho
 * prod-safe para arquivos grandes, que estouram o timeout do request HTTP (ex.: TOPLIFE I ~2826
 * obrigações → 504 no nginx). Envolve a mesma dupla já testada: `TopLifeInadimplenciaAdapter::ler`
 * (fonte-específico, §21) + `ImportarRelatorioCarteiraUseCase::prever/confirmar` (idempotente, dentro de
 * UMA carteira explícita). NÃO reimplementa regra nenhuma — só substitui a fonte HTTP pela CLI.
 *
 * Sem `--confirmar` roda um DRY-RUN honesto (prever, não persiste). A decisão da Etapa 7 vale: linha
 * só-encargos é REJEITADA com motivo (aparece no resumo). Idempotente: reimportar o mesmo arquivo
 * atualiza encargos, não duplica.
 *
 * Uso (rodar com memória folgada — a carga grande passa de 128M):
 *   php -d memory_limit=512M bin/console app:cobranca:importar \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/toplife_1.xlsx            # dry-run
 *   php -d memory_limit=512M bin/console app:cobranca:importar \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/toplife_1.xlsx --confirmar # persiste
 */
#[AsCommand(
    name: 'app:cobranca:importar',
    description: 'Importa um relatório TOPLIFE (.xlsx) numa carteira de cobrança via CLI (sem timeout HTTP)',
)]
final class ImportarRelatorioCobrancaCommand extends Command
{
    public function __construct(
        private readonly ImportarRelatorioCarteiraUseCase $importar,
        private readonly TopLifeInadimplenciaAdapter $adapter,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório (tenant) dono da carteira')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'ID da carteira de cobrança destino')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário (membro do tenant) que assina a importação')
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx TOPLIFE a importar')
            ->addOption('confirmar', null, InputOption::VALUE_NONE, 'Persiste (sem esta flag é dry-run/prever)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantId = (int) $input->getOption('tenant-id');
        $carteiraId = (int) $input->getOption('carteira-id');
        $usuarioId = (int) $input->getOption('usuario-id');
        $arquivo = (string) $input->getOption('arquivo');
        $confirmar = (bool) $input->getOption('confirmar');

        if ($tenantId <= 0 || $carteiraId <= 0 || $usuarioId <= 0 || $arquivo === '') {
            $io->error('Informe --tenant-id, --carteira-id, --usuario-id e --arquivo.');

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant %d não encontrado.', $tenantId));

            return Command::FAILURE;
        }

        $usuario = $this->em->find(User::class, $usuarioId);
        if ($usuario === null) {
            $io->error(sprintf('Usuário %d não encontrado.', $usuarioId));

            return Command::FAILURE;
        }

        // Tenant-safety: o usuário que assina a importação PRECISA ser membro do tenant.
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $usuario, 'tenant' => $tenant]);
        if ($vinculo === null) {
            $io->error(sprintf('Usuário %d não pertence ao tenant %d — importação recusada.', $usuarioId, $tenantId));

            return Command::FAILURE;
        }

        if (!is_file($arquivo) || !is_readable($arquivo)) {
            $io->error(sprintf('Arquivo não encontrado ou ilegível: %s', $arquivo));

            return Command::FAILURE;
        }

        try {
            $leitura = $this->adapter->ler($arquivo);
        } catch (\Throwable $e) {
            $io->error('Não foi possível ler o relatório (confira se é a planilha TOPLIFE correta): ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->section(sprintf('Leitura do arquivo (%s)', basename($arquivo)));
        $io->listing([
            sprintf('Boletos importáveis: %d', \count($leitura->importaveis)),
            sprintf('Linhas rejeitadas: %d', \count($leitura->rejeitadas)),
            sprintf('Linhas ignoradas (rodapé/cabeçalho): %d', $leitura->linhasIgnoradas),
        ]);

        try {
            $resultado = $confirmar
                ? $this->importar->confirmar($carteiraId, $leitura, $tenant, $usuario)
                : $this->importar->prever($carteiraId, $leitura, $tenant);
        } catch (\Throwable $e) {
            $io->error(sprintf('Falha na importação (carteira %d): %s', $carteiraId, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->section($confirmar ? 'CONFIRMADO — persistido' : 'DRY-RUN — nada foi persistido');
        $io->table(['Métrica', 'Total'], [
            ['Obrigações criadas', $resultado->totalImportadas()],
            ['— dessas, dívida SEM boleto (nunca cobrada)', $resultado->totalSemBoleto()],
            ['Atualizadas que são dívida SEM boleto', $resultado->totalSemBoletoAtualizadas()],
            ['Dívida SEM boleto no lote (principal + encargos)', 'R$ ' . number_format($resultado->centavosSemBoleto / 100, 2, ',', '.')],
            ['Obrigações atualizadas (reimport)', $resultado->totalAtualizadas()],
            ['Objetos criados', $resultado->objetosCriados],
            ['Pessoas criadas', $resultado->pessoasCriadas],
            ['Casos criados', $resultado->casosCriados],
            ['Sacados divergentes (só reportado)', \count($resultado->sacadosDivergentes)],
            ['Rejeitadas', $resultado->totalRejeitadas()],
            ['Ignoradas', $resultado->linhasIgnoradas],
        ]);

        if ($resultado->rejeitadas !== []) {
            $io->section('Rejeitadas (amostra — motivo)');
            $amostra = [];
            foreach (\array_slice($resultado->rejeitadas, 0, 15) as $rej) {
                $amostra[] = [$rej->referencia, $rej->motivo];
            }
            $io->table(['Referência', 'Motivo'], $amostra);
            if (\count($resultado->rejeitadas) > 15) {
                $io->text(sprintf('… e mais %d rejeitadas.', \count($resultado->rejeitadas) - 15));
            }
        }

        if (!$confirmar) {
            $io->warning('DRY-RUN: nada foi gravado. Repita com --confirmar para persistir.');
        } else {
            $io->success('Importação concluída e persistida.');
        }

        return Command::SUCCESS;
    }
}
