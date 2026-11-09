<?php

declare(strict_types=1);

namespace App\Ponto\Command;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Repository\FeriadoRepository;
use App\Ponto\Service\BancoDeHorasPorLotes;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\InicioContagemResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * O banco de horas de cada pessoa antes e depois da troca para lotes (spec ponto-desenho-0811, E17, P51 a): a spec
 * pede o número de todo mundo registrado no PR, porque a troca muda o saldo de todos.
 *
 * "Antes" é o banco como era até a E17: só as competências do ano corrente (zerava em 1º de janeiro), com as horas
 * pagas lançadas em qualquer mês do ano. "Depois" é o saldo atual dos lotes, o número que a tela passa a mostrar.
 * As duas colunas saem dos mesmos meses (`FolhaPontoBuilder::saldosDoBancoPorCompetencia`), então a diferença é só a
 * virada do ano.
 *
 * **Somente leitura.** Não grava nada. A saída tem só ids (escritório e pessoa), sem nome nem CPF.
 */
#[AsCommand(
    name: 'app:ponto:banco:comparar-virada',
    description: 'Banco de horas de cada pessoa antes (zerava em janeiro) e depois (lotes), só com ids (somente leitura)',
)]
final class CompararViradaDoBancoDeHorasCommand extends Command
{
    /** Pessoas com algum registro de ponto (batida, abono) ou lançamento de horas pagas, por escritório. */
    private const PESSOAS_COM_BANCO = <<<'SQL'
        SELECT tenant_id, user_id FROM (
            SELECT tenant_id, user_id FROM registro_ponto
            UNION SELECT tenant_id, user_id FROM ponto_lancamento_horas_pagas
            UNION SELECT tenant_id, user_id FROM justificativa_ponto WHERE status = 'abonado'
        ) AS pessoa
        WHERE tenant_id IS NOT NULL AND user_id IS NOT NULL
        SQL;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FolhaPontoBuilder $folha,
        private readonly BancoDeHorasPorLotes $lotes,
        private readonly InicioContagemResolver $inicioContagem,
        private readonly FeriadoRepository $feriados,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('escritorio', null, InputOption::VALUE_REQUIRED, 'Só este escritório (id)')
            ->addOption('so-diferentes', null, InputOption::VALUE_NONE, 'Lista só quem muda de saldo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hoje = new \DateTimeImmutable('today');
        $escritorio = $input->getOption('escritorio');
        $linhas = $this->comparar($escritorio === null ? null : (int) $escritorio, $hoje);
        $diferentes = array_values(array_filter($linhas, static fn (array $linha): bool => $linha[4] !== 0));

        $io->title(sprintf('Banco de horas: antes (só %d) e depois (lotes, sem zerar em janeiro)', (int) $hoje->format('Y')));
        $io->text('Somente leitura. Nenhum saldo é gravado. Minutos entre parênteses.');
        $io->table(
            ['Escritório', 'Pessoa', 'Antes', 'Depois', 'Diferença'],
            array_map(fn (array $linha): array => $this->formatar($linha), $input->getOption('so-diferentes') ? $diferentes : $linhas),
        );
        $io->text(sprintf('%d pessoas; %d com saldo diferente.', count($linhas), count($diferentes)));

        return Command::SUCCESS;
    }

    /** @return list<array{int, int, int, int, int}> escritório, pessoa, antes, depois e diferença, em minutos */
    private function comparar(?int $escritorio, \DateTimeImmutable $hoje): array
    {
        $sql = self::PESSOAS_COM_BANCO . ($escritorio === null ? '' : ' AND tenant_id = :escritorio');
        $pessoas = $this->em->getConnection()->fetchAllAssociative(
            $sql . ' ORDER BY tenant_id, user_id',
            $escritorio === null ? [] : ['escritorio' => $escritorio],
        );

        $linhas = [];
        foreach ($pessoas as $pessoa) {
            $tenant = $this->em->find(Tenant::class, (int) $pessoa['tenant_id']);
            $user = $this->em->find(User::class, (int) $pessoa['user_id']);
            if ($tenant === null || $user === null) {
                continue;
            }
            $antes = $this->saldoDoAno($user, $tenant, (int) $hoje->format('Y'));
            $depois = $this->lotes->calcular($user, $tenant, $hoje)->saldoAtualMinutos;
            $linhas[] = [(int) $tenant->getId(), (int) $user->getId(), $antes, $depois, $depois - $antes];
            $this->em->clear();
        }

        return $linhas;
    }

    /** O banco como era: só as competências do ano pedido. */
    private function saldoDoAno(User $user, Tenant $tenant, int $ano): int
    {
        $saldos = $this->folha->saldosDoBancoPorCompetencia(
            $user,
            $tenant,
            $this->feriados->findByTenant($tenant),
            $tenant->getJornadaTenant(),
            $this->inicioContagem->resolver($user, $tenant),
        );
        $prefixo = sprintf('%04d-', $ano);

        return array_sum(array_filter($saldos, static fn (string $competencia): bool => str_starts_with($competencia, $prefixo), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param array{int, int, int, int, int} $linha
     * @return list<string>
     */
    private function formatar(array $linha): array
    {
        [$escritorio, $pessoa, $antes, $depois, $diferenca] = $linha;

        return [(string) $escritorio, (string) $pessoa, $this->horas($antes), $this->horas($depois), $this->horas($diferenca)];
    }

    /** "+12h30m (750)", o formato do desenho com os minutos ao lado. */
    private function horas(int $minutos): string
    {
        $absoluto = abs($minutos);
        $texto = $minutos === 0 ? '0h' : sprintf('%s%dh%02dm', $minutos < 0 ? '-' : '+', intdiv($absoluto, 60), $absoluto % 60);

        return sprintf('%s (%d)', $texto, $minutos);
    }
}
