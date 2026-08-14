<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\CoberturaDoEspelho;
use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
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
final class CalibrarEspelhoCommand extends Command implements LidaComDadoPessoal
{
    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly CoberturaDoEspelho $cobertura,
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
            ->addOption(
                'aceito-log-com-pii',
                null,
                InputOption::VALUE_NONE,
                'Roda mesmo com o log de SQL ligado. A saída conterá CPF, e-mail e telefone.',
            )
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Calibra só esta carteira')
            ->addOption('detalhar', null, InputOption::VALUE_NONE, 'Lista as piores diferenças');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 🔴 ANTES de qualquer leitura: o log verboso do Doctrine imprime CPF, e-mail e
        // telefone de condômino. Ver {@see GuardaDeLogComPii}.
        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:espelho:calibrar')) {
            return GuardaDeLogComPii::LOG_COM_PII;
        }

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
            // INV-Q7: o que este número cobre vem ANTES do número. Este instrumento lê só a
            // inadimplência — e é justamente por isso que a linha precisa estar aqui.
            $veredito = $this->cobertura->declarar($io, $carteira, [TipoRelatorioContabil::Inadimplencia]);
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

            // Os três motivos SEPARADOS: um contador só diria a mesma frase para "não casou com o
            // sistema" (grave) e para "ainda não venceu" (normal). O `sem base` é o que esconde
            // não-espelhamento (INV-CB3) e por isso vem com aviso quando existe.
            $io->text(sprintf(
                'linhas comparadas: %d · fora: %d (sem par no sistema: %d · sem atraso: %d · sem base: %d)',
                $r->comparadas,
                $r->foraDaCalibracao(),
                $r->semParNoSistema,
                $r->semAtraso,
                $r->semBase,
            ));

            if ($r->semBase > 0) {
                // O texto descreve a CONDIÇÃO (valor não positivo), não o caso que hoje a preenche.
                // Hoje 100% delas são linhas de desconto (classe 1.6) — medido, 26 de 26 no acervo —,
                // mas a guarda também pega valor zero, e prometer "é desconto" viraria mentira no dia
                // em que aparecer o primeiro zero.
                $io->text(sprintf(
                    '  ↳ %d linha(s) de valor não positivo (tipicamente desconto, classe 1.6): a contabilidade '
                    . 'lança encargo negativo nelas e a nossa fórmula não calcula sobre base não positiva. '
                    . 'Elas se reconciliam no boleto, mas ficam fora desta conta (INV-CB3).',
                    $r->semBase,
                ));
            }

            if ($r->comparadas > 0) {
                $io->text(sprintf('exatos: %.2f%%', $r->percentualExato()));
            }

            match ($r->veredito()) {
                'bate' => $veredito->sucesso($io, 'A nossa conta é a conta deles. A projeção entre imports está validada.'),
                'bate quase' => $veredito->sucesso(
                    $io,
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
