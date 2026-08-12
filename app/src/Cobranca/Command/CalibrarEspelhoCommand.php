<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\CalibracaoDoEspelho;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mede se a nossa conta de encargo é a mesma da contabilidade
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §6).
 *
 * **Somente leitura.** Não escreve nada e não altera nenhum encargo.
 *
 * É a pergunta que decide o desenho do módulo: se a projeção reproduz a conta deles, o sistema pode
 * seguir sozinho entre um import e o próximo. Os três desfechos e o que cada um decide estão na
 * §6.4 da spec — e o terceiro deles ("não bate") é **achado para levar à contabilidade**, nunca
 * motivo para ajustar a fórmula até fechar.
 */
#[AsCommand(
    name: 'app:cobranca:espelho:calibrar',
    description: 'Mede a nossa fórmula de encargo contra a da contabilidade (somente leitura)',
)]
final class CalibrarEspelhoCommand extends Command
{
    public function __construct(
        private readonly CalibracaoDoEspelho $calibracao,
        private readonly RelatorioImportadoRepository $relatorios,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Calibra só esta carteira')
            ->addOption('detalhar', null, InputOption::VALUE_NONE, 'Lista as piores diferenças');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return Command::FAILURE;
        }

        $criterio = ['tenant' => $tenant];
        $carteiraId = $input->getOption('carteira-id');

        if ($carteiraId !== null) {
            $criterio['id'] = (int) $carteiraId;
        }

        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy($criterio);

        $io->title('Nossa conta × a conta da contabilidade');
        $io->text('Somente leitura. Nenhum encargo é alterado.');

        foreach ($carteiras as $carteira) {
            $lote = $this->relatorios->findUltimoDaCarteira($carteira);

            if ($lote === null) {
                continue;
            }

            $r = $this->calibracao->calibrar($lote);

            $io->section(sprintf('%s — calculado em %s', $r->carteira, $r->dadosAte?->format('d/m/Y') ?? '?'));

            $io->table(
                ['diferença', 'linhas'],
                array_map(
                    static fn (string $k, int $v): array => [$k, $v],
                    array_keys($r->faixas),
                    array_values($r->faixas),
                )
            );

            $io->text(sprintf(
                'linhas comparadas: %d · fora da calibração (sem par no sistema, sem atraso ou sem base): %d',
                $r->comparadas,
                $r->foraDaCalibracao,
            ));

            if ($r->comparadas > 0) {
                $io->text(sprintf('exatos: %.2f%%', $r->percentualExato()));
            }

            match ($r->veredito()) {
                'bate' => $io->success('A nossa conta é a conta deles. A projeção entre imports está validada.'),
                'bate quase' => $io->success(
                    'Diferenças só de arredondamento: a projeção vale, e cada import reancora — a diferença zera.'
                ),
                'nao bate' => $io->warning(
                    'Há diferença de REGRA, não de arredondamento. Isto é achado para levar à contabilidade; '
                    . 'não ajuste a fórmula até fechar sem entender a causa.'
                ),
                default => $io->warning('Sem dado suficiente para calibrar esta carteira.'),
            };

            if ($input->getOption('detalhar') && $r->piores !== []) {
                $io->table(
                    ['unidade', 'NN', 'classe', 'campo', 'nosso', 'deles', 'diferença'],
                    array_map(
                        fn (array $p): array => [
                            $p['unidade'],
                            $p['nn'] ?? '—',
                            $p['classe'] ?? '—',
                            $p['campo'],
                            $this->reais($p['nosso']),
                            $this->reais($p['deles']),
                            $this->reais($p['diferenca']),
                        ],
                        $r->piores,
                    )
                );
            }
        }

        return Command::SUCCESS;
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
