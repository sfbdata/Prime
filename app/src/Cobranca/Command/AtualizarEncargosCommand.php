<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Exception\EncargosInexequiveisException;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron diário que faz os encargos CRESCEREM no tempo (spec "encargos configuráveis em cascata" §6-B,
 * fase F3).
 *
 * Por que existe: juros de mora mudam TODO DIA. A alternativa seria `valorExigivel($hoje)` derivado
 * ao vivo, o que obrigaria `CalculadoraSaldo`, `AutoAlocadorFifo`, Dashboard e Acordo a receberem uma
 * data — invasivo demais num módulo que já está em produção (INV-E3). Em vez disso os quatro encargos
 * são MATERIALIZADOS nas colunas da obrigação e este comando os recalcula para "hoje". Entre duas
 * execuções o valor fica no máximo um dia defasado (~0,033% ao dia), o que é aceitável num sistema de
 * registro — a contabilidade segue sendo a fonte de verdade.
 *
 * O que ele NÃO faz, de propósito:
 *  - **nunca congela** nada (`congelarEncargos` é da edição manual/importação, §8);
 *  - **nunca toca** obrigação congelada (INV-E4) — quem editou à mão ou importou da contabilidade
 *    mandou parar de crescer, e um cron não desfaz decisão de gente;
 *  - **nunca toca** obrigação de acordo vigente nem substituída: aquela dívida foi renegociada e já
 *    virou parcela; inflar os juros dela cobraria duas vezes;
 *  - **nunca toca** caso encerrado (SPEC §17, filtrado já na query).
 *
 * Multi-tenant no CLI: o `TenantFilter` fica DESLIGADO fora de um request. Por isso o comando itera os
 * escritórios e passa o `Tenant` EXPLICITAMENTE ao repositório em toda query — é a única defesa contra
 * um escritório recalcular dinheiro de outro.
 *
 * Idempotente: rodar duas vezes no mesmo dia não muda nada na segunda (os valores batem e nem UPDATE
 * é emitido). Um lock (flock) impede execuções sobrepostas.
 *
 * Uso:
 *   php bin/console app:cobranca:atualizar-encargos --dry-run          # relata, não grava
 *   php bin/console app:cobranca:atualizar-encargos --tenant=1
 *   php bin/console app:cobranca:atualizar-encargos --hoje=2026-07-19  # data de referência fixa
 */
#[AsCommand(
    name: 'app:cobranca:atualizar-encargos',
    description: 'Recalcula os encargos materializados das obrigações não congeladas (cron diário)',
)]
final class AtualizarEncargosCommand extends Command
{
    /**
     * Tamanho do lote carregado em memória por vez. Entre lotes há `flush()` + `clear()`: o job roda
     * sobre dezenas de milhares de obrigações e não pode segurar o grafo inteiro.
     */
    private const TAMANHO_LOTE = 200;

    /** Quantas falhas aparecem no relatório antes do "… e mais N". */
    private const AMOSTRA_DE_FALHAS = 15;

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly CalculadoraEncargos $calculadora,
        private readonly ResolvedorConfigEncargos $resolvedor,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Calcula e relata, mas NÃO persiste nada.')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restringe a um escritório específico (id).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Processa só as N primeiras obrigações de cada escritório (amostra).')
            ->addOption('hoje', null, InputOption::VALUE_REQUIRED, 'Data de referência do cálculo (Y-m-d). Default: hoje.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $hoje = $this->resolverDataReferencia($input, $io);
        if ($hoje === null) {
            return Command::INVALID;
        }

        $limite = $this->resolverLimite($input, $io);
        if ($limite === false) {
            return Command::INVALID;
        }

        if ($this->adquirirLock() === false) {
            // SUCCESS e não FAILURE: sobreposição não é erro — a próxima rodada pega o que faltou.
            $io->warning('Outra atualização de encargos já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            $tenantIds = $this->resolverEscritorios($input, $io);
            if ($tenantIds === null) {
                return Command::FAILURE;
            }

            if ($tenantIds === []) {
                $io->note('Nenhum escritório ativo para atualizar.');

                return Command::SUCCESS;
            }

            if ($dryRun === true) {
                $io->note('Modo simulação (--dry-run): nada será gravado.');
            }

            $io->text(sprintf('Data de referência do cálculo: %s', $hoje->format('d/m/Y')));

            // Contadores em tipos PHP simples (int/array de escalares): sobrevivem ao `em->clear()`
            // entre lotes. Guardar uma ENTIDADE aqui seria bug — no lote seguinte ela estaria
            // destacada e qualquer leitura dispararia lazy-load numa unit of work vazia.
            $examinadas = 0;
            $atualizadas = 0;
            $inalteradas = 0;
            $puladas = 0;
            $deltaExigivelCentavos = 0;
            /** @var list<array{0:int, 1:string}> $falhas */
            $falhas = [];

            foreach ($tenantIds as $tenantId) {
                $tenant = $this->tenantRepository->find($tenantId);
                if (!$tenant instanceof Tenant) {
                    continue;
                }

                $ids = $this->obrigacaoRepository->idsParaAtualizarEncargos($tenant, $limite);

                foreach (array_chunk($ids, self::TAMANHO_LOTE) as $lote) {
                    // Re-busca GERENCIADA do tenant: o `clear()` do lote anterior o destacou.
                    $tenantGerenciado = $this->tenantRepository->find($tenantId);
                    if (!$tenantGerenciado instanceof Tenant) {
                        break;
                    }

                    $obrigacoes = $this->obrigacaoRepository->loteParaAtualizarEncargos($lote, $tenantGerenciado);

                    foreach ($obrigacoes as $obrigacao) {
                        ++$examinadas;

                        if ($this->devePular($obrigacao)) {
                            ++$puladas;

                            continue;
                        }

                        try {
                            $delta = $this->atualizar($obrigacao, $hoje);
                        } catch (EncargosInexequiveisException $e) {
                            // Contrato explícito da exceção: em lote, captura POR OBRIGAÇÃO, registra
                            // e SEGUE — um caso patológico não pode matar a rodada inteira.
                            $falhas[] = [(int) $obrigacao->getId(), $e->getMessage()];

                            continue;
                        } catch (\Throwable $e) {
                            $falhas[] = [(int) $obrigacao->getId(), sprintf('%s: %s', $e::class, $e->getMessage())];

                            continue;
                        }

                        if ($delta === null) {
                            ++$inalteradas;

                            continue;
                        }

                        ++$atualizadas;
                        $deltaExigivelCentavos += $delta;
                    }

                    // Dry-run NÃO chama flush(): é a garantia à prova de falha de que nada é gravado
                    // (mais simples e mais segura que abrir transação para dar rollback depois).
                    if ($dryRun === false) {
                        $this->em->flush();
                    }

                    // Higiene de memória — e, no dry-run, descarte explícito das entidades sujas em
                    // memória, para que nenhum flush alheio adiante as persista por acidente.
                    $this->em->clear();
                }
            }

            $io->table(
                ['Métrica', 'Total'],
                [
                    ['Obrigações examinadas', (string) $examinadas],
                    ['Atualizadas', (string) $atualizadas],
                    ['Inalteradas', (string) $inalteradas],
                    ['Puladas (congeladas/acordo)', (string) $puladas],
                    ['Falhas', (string) \count($falhas)],
                    ['Delta do exigível (centavos)', (string) $deltaExigivelCentavos],
                    ['Delta do exigível', self::formatarReais($deltaExigivelCentavos)],
                ],
            );

            if ($falhas !== []) {
                $io->section('Obrigações que falharam');
                foreach (array_slice($falhas, 0, self::AMOSTRA_DE_FALHAS) as [$id, $mensagem]) {
                    $io->text(sprintf('  <comment>#%d: %s</comment>', $id, $mensagem));
                }

                $restantes = \count($falhas) - self::AMOSTRA_DE_FALHAS;
                if ($restantes > 0) {
                    $io->text(sprintf('  … e mais %d', $restantes));
                }
            }

            if ($dryRun === true) {
                $io->warning('DRY-RUN: nada foi gravado.');
            } else {
                $io->success(sprintf(
                    '%d obrigação(ões) atualizada(s), %d inalterada(s), %d pulada(s).',
                    $atualizadas,
                    $inalteradas,
                    $puladas,
                ));
            }

            if ($falhas !== []) {
                // FAILURE só por falha em obrigação: é o que faz o cron alarmar e o log ser lido.
                $io->error(sprintf('%d obrigação(ões) falharam no recálculo — verifique o log.', \count($falhas)));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * Obrigação que o cron NÃO pode tocar.
     *
     * Congelada (INV-E4) já é excluída pela query — a checagem aqui é defesa em profundidade, para o
     * dia em que alguém chamar o lote por outro caminho.
     *
     * Acordo vigente / substituída: aquela dívida foi renegociada e virou parcela de acordo. Crescer
     * os juros dela cobraria algo que já foi substituído (invariáveis 14/15).
     */
    private function devePular(Obrigacao $obrigacao): bool
    {
        return $obrigacao->encargosCongelados()
            || $obrigacao->participaDeAcordoVigente()
            || $obrigacao->foiSubstituida();
    }

    /**
     * Recalcula e materializa os encargos de uma obrigação.
     *
     * @return int|null delta do valor EXIGÍVEL em centavos (juros+multa+correção; honorários ficam de
     *                  fora por INV-E2), ou `null` quando nada mudou
     */
    private function atualizar(Obrigacao $obrigacao, \DateTimeImmutable $hoje): ?int
    {
        $config = $this->resolvedor->resolver($obrigacao);

        $novos = $this->calculadora->calcular(
            $obrigacao->getValorOriginal(),
            $obrigacao->getVencimentoOriginal(),
            $config,
            $hoje,
        );

        $jurosAntes = $obrigacao->getJuros();
        $multaAntes = $obrigacao->getMulta();
        $correcaoAntes = $obrigacao->getCorrecao();
        $honorariosAntes = $obrigacao->getHonorarios();

        // Nada mudou: não chama `definirEncargos`. Evita UPDATE inútil (o cron varre a base inteira
        // todo dia) e, sobretudo, não mexe em `encargosAtualizadosEm` — a tela mostraria uma data
        // nova para um valor que é o mesmo desde sempre.
        if (
            $novos['juros'] === $jurosAntes
            && $novos['multa'] === $multaAntes
            && $novos['correcao'] === $correcaoAntes
            && $novos['honorarios'] === $honorariosAntes
        ) {
            return null;
        }

        $obrigacao->definirEncargos(
            $novos['juros'],
            $novos['multa'],
            $novos['correcao'],
            $novos['honorarios'],
            $hoje,
        );

        return ($novos['juros'] + $novos['multa'] + $novos['correcao'])
            - ($jurosAntes + $multaAntes + $correcaoAntes);
    }

    /**
     * Data de referência do cálculo. Injetável (`--hoje`) de propósito: é o que torna um cron de
     * DINHEIRO testável e auditável — o mesmo input tem de dar o mesmo output sempre. A hora é zerada
     * para que a rodada das 03:30 e uma rodada manual do mesmo dia produzam o mesmo número.
     *
     * @return \DateTimeImmutable|null null = data inválida (o chamador devolve INVALID)
     */
    private function resolverDataReferencia(InputInterface $input, SymfonyStyle $io): ?\DateTimeImmutable
    {
        $opcao = $input->getOption('hoje');

        if ($opcao === null) {
            return new \DateTimeImmutable('today');
        }

        $texto = trim((string) $opcao);
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        if ($data === false || $data->format('Y-m-d') !== $texto) {
            $io->error(sprintf('Data inválida em --hoje: "%s". Use o formato Y-m-d (ex.: 2026-07-19).', $texto));

            return null;
        }

        return $data;
    }

    /**
     * Limite de obrigações por escritório (amostra). `false` = valor inválido.
     *
     * @return int|null|false
     */
    private function resolverLimite(InputInterface $input, SymfonyStyle $io): int|null|false
    {
        $opcao = $input->getOption('limit');

        if ($opcao === null) {
            return null;
        }

        $texto = trim((string) $opcao);
        if (ctype_digit($texto) === false || (int) $texto <= 0) {
            $io->error(sprintf('Valor inválido em --limit: "%s". Informe um inteiro positivo.', $texto));

            return false;
        }

        return (int) $texto;
    }

    /**
     * Ids dos escritórios a processar. Retorna null quando um `--tenant` específico não existe.
     *
     * @return int[]|null
     */
    private function resolverEscritorios(InputInterface $input, SymfonyStyle $io): ?array
    {
        $tenantOpt = $input->getOption('tenant');

        if ($tenantOpt !== null) {
            $id = (int) $tenantOpt;
            $tenant = $this->tenantRepository->find($id);
            if (!$tenant instanceof Tenant) {
                $io->error(sprintf('Escritório #%d não encontrado.', $id));

                return null;
            }

            return [$id];
        }

        $ids = [];
        foreach ($this->tenantRepository->findAll() as $tenant) {
            if ($tenant->isActive() === true) {
                $ids[] = (int) $tenant->getId();
            }
        }

        return $ids;
    }

    /**
     * Centavos → "R$ 1.234,56" por aritmética INTEIRA (nada de dividir por 100 em float, mesmo para
     * exibir: o relatório de um cron de dinheiro é lido como prova).
     */
    private static function formatarReais(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return sprintf(
            '%sR$ %s,%02d',
            $sinal,
            number_format(intdiv($absoluto, 100), 0, ',', '.'),
            $absoluto % 100,
        );
    }

    /**
     * Lock exclusivo por lockfile (flock) para impedir execuções sobrepostas — coerente com a stack
     * do projeto (sem symfony/lock), mesmo idioma do `SincronizarDjenCommand`.
     */
    private function adquirirLock(): bool
    {
        $handle = fopen(sys_get_temp_dir() . '/jusprime-cobranca-encargos.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function liberarLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
