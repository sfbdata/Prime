<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\ResultadoConferencia;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Enum\TipoRelatorioContabil;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\CoberturaDoEspelho;
use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\Service\Espelho\ConferenciaDoEspelho;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Responde "o sistema está alinhado com a contabilidade?"
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §5).
 *
 * **Somente leitura.** Não escreve em tabela nenhuma.
 *
 * Compara, por carteira, o último relatório espelhado com o que o sistema considera em aberto:
 * o mesmo conjunto de dívidas, e o mesmo principal em cada uma. Encargo NÃO é conferido aqui — ele
 * é do sistema e diverge da planilha por desenho (a contabilidade calculou o dela num instante
 * anterior); quem mede essa diferença é a calibração.
 *
 * Uso:
 *   php bin/console app:cobranca:espelho:conferir --tenant-id=1
 *   php bin/console app:cobranca:espelho:conferir --tenant-id=1 --carteira-id=3 --detalhar
 */
#[AsCommand(
    name: 'app:cobranca:espelho:conferir',
    description: 'Confere o sistema contra o relatório da contabilidade (somente leitura)',
)]
final class ConferirEspelhoCommand extends Command implements LidaComDadoPessoal
{
    private const AMOSTRA = 15;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly CoberturaDoEspelho $cobertura,
        private readonly ConferenciaDoEspelho $conferencia,
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
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Confere só esta carteira')
            ->addOption('detalhar', null, InputOption::VALUE_NONE, 'Lista as divergências, não só a contagem');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 🔴 ANTES de qualquer leitura: o log verboso do Doctrine imprime CPF, e-mail e
        // telefone de condômino. Ver {@see GuardaDeLogComPii}.
        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:espelho:conferir')) {
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

        if ($carteiras === []) {
            $io->error('Nenhuma carteira encontrada.');

            return Command::FAILURE;
        }

        $io->title('Sistema × contabilidade');
        $io->text('Somente leitura. O encargo não é conferido aqui — é do sistema, e diverge por desenho.');

        $desalinhadas = 0;
        $quebrou = false;

        foreach ($carteiras as $carteira) {
            // INV-Q7: o que este número cobre vem ANTES do número. Esta conferência lê só a
            // inadimplência — e é justamente por isso que a linha precisa estar aqui.
            $veredito = $this->cobertura->declarar($io, $carteira, [TipoRelatorioContabil::Inadimplencia]);

            $lote = $this->relatorios->findUltimoDaCarteira($carteira);

            if ($lote === null) {
                $io->section((string) $carteira->getNome());
                $io->warning('Sem relatório espelhado. Rode app:cobranca:espelho:carregar antes.');

                continue;
            }

            $resultado = $this->conferencia->conferir($lote);

            $io->section(sprintf(
                '%s — contabilidade até %s',
                $resultado->carteira,
                $resultado->dadosAte?->format('d/m/Y') ?? '?'
            ));

            $io->table(
                ['balde', 'quantidade'],
                array_map(
                    static fn (string $k, int $v): array => [$k, $v],
                    array_keys($resultado->resumo()),
                    array_values($resultado->resumo()),
                )
            );

            $io->text(sprintf(
                'grupos no relatório: %d · obrigações em aberto no sistema: %d',
                $resultado->gruposNoRelatorio,
                $resultado->obrigacoesNoSistema,
            ));
            $io->text(sprintf(
                'principal — relatório %s · sistema %s · diferença %s',
                $this->reais($resultado->principalRelatorio),
                $this->reais($resultado->principalSistema),
                $this->reais($resultado->principalRelatorio - $resultado->principalSistema),
            ));

            if (!$resultado->baldesFecham()) {
                // INV-RC1: número incompleto com cara de completo é pior do que número nenhum.
                $io->error('Os baldes NÃO somam o total de grupos — a conferência está errada, não os dados.');
                $quebrou = true;

                continue;
            }

            if ($input->getOption('detalhar')) {
                $this->detalhar($io, $resultado);
            }

            if ($resultado->alinhado()) {
                // Verde SÓ se a cobertura permitir — quem decide é o veredito, não este comando.
                $veredito->sucesso($io, 'Alinhado: mesmo conjunto de dívidas e mesmo principal.');

                continue;
            }

            ++$desalinhadas;
        }

        if ($quebrou) {
            return Command::FAILURE;
        }

        if ($desalinhadas > 0) {
            $io->warning(sprintf('%d carteira(s) com divergência. Use --detalhar para a lista.', $desalinhadas));
        }

        return Command::SUCCESS;
    }

    private function detalhar(SymfonyStyle $io, ResultadoConferencia $resultado): void
    {
        if ($resultado->faltaNoSistema !== []) {
            $io->text(sprintf('<comment>Falta no sistema (%d):</comment>', count($resultado->faltaNoSistema)));
            $io->table(
                ['unidade', 'NN', 'competência', 'motivo'],
                array_map(
                    static fn (array $f): array => [$f['unidade'], $f['nn'] ?? '—', $f['competencia'] ?? '—', $f['motivo']],
                    array_slice($resultado->faltaNoSistema, 0, self::AMOSTRA),
                )
            );
            $this->avisarCorte($io, count($resultado->faltaNoSistema));
        }

        if ($resultado->sobraNoSistema !== []) {
            $io->text(sprintf('<comment>Sobra no sistema (%d):</comment>', count($resultado->sobraNoSistema)));
            $io->table(
                ['unidade', 'referência', 'competência'],
                array_map(
                    static fn (array $s): array => [$s['unidade'], $s['nn'] ?? '—', $s['competencia'] ?? '—'],
                    array_slice($resultado->sobraNoSistema, 0, self::AMOSTRA),
                )
            );
            $this->avisarCorte($io, count($resultado->sobraNoSistema));
        }

        if ($resultado->principalDiferente !== []) {
            $io->text(sprintf('<comment>Principal diferente (%d):</comment>', count($resultado->principalDiferente)));
            $io->table(
                ['unidade', 'relatório', 'sistema', 'diferença'],
                array_map(
                    fn (array $p): array => [
                        $p['unidade'],
                        $this->reais($p['relatorio']),
                        $this->reais($p['sistema']),
                        $this->reais($p['diferenca']),
                    ],
                    array_slice($resultado->principalDiferente, 0, self::AMOSTRA),
                )
            );
            $this->avisarCorte($io, count($resultado->principalDiferente));
        }

        if ($resultado->ambiguidadeDeCaso !== []) {
            $io->warning(sprintf(
                'Ambiguidade de caso em %d dívida(s): a unidade tem mais de um caso, e escolher um daria '
                . 'divergência falsa. Resolva o cadastro antes de confiar nestes.',
                count($resultado->ambiguidadeDeCaso)
            ));
        }
    }

    private function avisarCorte(SymfonyStyle $io, int $total): void
    {
        if ($total > self::AMOSTRA) {
            // Truncar em silêncio faria a lista parecer completa.
            $io->text(sprintf('  <comment>… e mais %d. A tabela acima é amostra.</comment>', $total - self::AMOSTRA));
        }
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
