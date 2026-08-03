<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\TopLifeReceitasAdapter;
use App\Cobranca\UseCase\ImportarReceitasUseCase;
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
 * Importa o relatório "Receitas detalhadas por unidade/cliente" (.xlsx) da contábil L.G — spec
 * `docs/specs/cobranca-importar-receitas.md`. É o 4º e último relatório, o que responde *o que foi pago*.
 *
 * Mesmo contrato dos irmãos: DRY-RUN por padrão, `--confirmar` persiste.
 *
 * **CLI e não tela** porque são ~5.100 linhas válidas: a tela morre no timeout de 30s (medido no
 * precedente da TOP LIFE I, 2.901 boletos → 500). Em produção: `scp` → `docker cp` →
 * `docker exec -w /var/www/app`, com `APP_DEBUG=0` e `memory_limit` alto.
 *
 * ⚠️ **O que este comando cria é diferente dos irmãos.** Ele não só registra recebimento: quando o
 * boleto não existe no sistema (medido: 96% dos casos), ele **cria a obrigação já paga** — decisão R1
 * do dono. Rodar isto contra uma carteira grande dobra o acervo. O dry-run existe para você ver esse
 * número ANTES.
 *
 * Uso:
 *   php bin/console app:cobranca:importar-receitas \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/receitas.xlsx          # dry-run
 *   php bin/console app:cobranca:importar-receitas ... --confirmar                       # persiste
 */
#[AsCommand(
    name: 'app:cobranca:importar-receitas',
    description: 'Importa as receitas detalhadas (.xlsx): registra o que foi pago e cria o boleto que o sistema não conhecia',
)]
final class ImportarReceitasCommand extends Command
{
    public function __construct(
        private readonly ImportarReceitasUseCase $importar,
        private readonly TopLifeReceitasAdapter $adapter,
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
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx de receitas detalhadas')
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

        if (!is_readable($arquivo)) {
            $io->error(sprintf('Arquivo não encontrado ou sem permissão de leitura: %s', $arquivo));

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant %d não encontrado.', $tenantId));

            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->find($usuarioId);
        if ($user === null) {
            $io->error(sprintf('Usuário %d não encontrado.', $usuarioId));

            return Command::FAILURE;
        }

        // Guarda multi-tenant: o usuário que assina precisa ser membro do escritório informado.
        if ($this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]) === null) {
            $io->error(sprintf('Usuário %d não pertence ao tenant %d.', $usuarioId, $tenantId));

            return Command::FAILURE;
        }

        $leitura = $this->adapter->ler($arquivo);
        $io->section(sprintf(
            'Leitura: %d recebimento(s) · %d em aberto (descartados) · %d rejeição(ões) · %d linha(s) de rodapé',
            count($leitura->receitas),
            $leitura->emAberto,
            count($leitura->rejeitadas),
            $leitura->linhasIgnoradas,
        ));

        if ($leitura->emAberto > 0) {
            $io->note(sprintf(
                '%d linha(s) com Recebimento "-" foram DESCARTADAS: são boletos em aberto, não receita. '
                . 'Se este número surpreender, o export foi feito incluindo a situação "Aberta".',
                $leitura->emAberto,
            ));
        }

        foreach ($leitura->rejeitadas as $rejeitada) {
            $io->writeln(sprintf('  <comment>%s</comment> — %s', $rejeitada->referencia, $rejeitada->motivo));
        }

        $resultado = $confirmar
            ? $this->importar->confirmar($carteiraId, $leitura, $tenant, $user)
            : $this->importar->prever($carteiraId, $leitura, $tenant);

        $this->imprimirTotais($io, $resultado, $confirmar);

        if (!$confirmar) {
            $io->warning(
                'DRY-RUN: nada foi gravado. Confira os números acima — em especial quantas obrigações '
                . 'seriam CRIADAS — e repita com --confirmar.',
            );

            return Command::SUCCESS;
        }

        $io->success('Receitas importadas.');
        $io->note(
            'O acervo cresceu: os boletos criados nascem QUITADOS e não mexem no saldo devedor, mas '
            . 'passam a aparecer como histórico pago das unidades.',
        );

        return Command::SUCCESS;
    }

    private function imprimirTotais(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        $io->section($confirmar ? 'O que foi feito' : 'O que aconteceria');
        $io->table(
            ['Item', 'Quantidade'],
            [
                ['Recebimentos registrados', count($r->pagamentosCriados)],
                ['— em obrigação que JÁ existia', count($r->obrigacoesExistentes)],
                ['— com obrigação CRIADA (boleto novo no sistema)', count($r->obrigacoesCriadas)],
                ['Já importados antes (ignorados)', count($r->jaImportados)],
                ['Unidades criadas', $r->objetosCriados],
                ['Pessoas criadas', $r->pessoasCriadas],
                ['Casos abertos', $r->casosCriados],
            ],
        );
        $io->table(
            ['Dinheiro', 'Valor'],
            [
                ['Total recebido (bruto do devedor)', $this->reais($r->totalRecebidoCentavos)],
                ['— honorários do escritório', $this->reais($r->honorariosCentavos)],
                ['— abatimento de dívida', $this->reais($r->recuperadoDividaCentavos())],
            ],
        );
        $io->note('Confira o total recebido contra a soma da coluna "Valor recebido" da planilha: têm de bater ao centavo.');
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
