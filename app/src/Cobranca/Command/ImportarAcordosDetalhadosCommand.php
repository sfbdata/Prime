<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\AcordoProcessado;
use App\Cobranca\Service\Importacao\AcordosDetalhadosAdapter;
use App\Cobranca\Service\Importacao\ResultadoImportacaoAcordos;
use App\Cobranca\UseCase\ImportarAcordosDetalhadosUseCase;
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
 * Importa o relatório "Acordos detalhados" (.xlsx) da contábil L.G numa Carteira — spec
 * `docs/specs/cobranca-importar-acordos-detalhados.md`.
 *
 * Mesmo contrato dos irmãos (`app:cobranca:importar`, `app:cobranca:importar-cadastro`): DRY-RUN por
 * padrão, `--confirmar` persiste. Não escreve regra nenhuma — junta o adapter da fonte com o UseCase.
 *
 * O DRY-RUN É O PRODUTO PRINCIPAL (§6): ele imprime, por acordo, as parcelas que criará e as contas
 * originais que marcará como substituídas, com unidade e sacado. Isto mexe em dinheiro que já está em
 * produção — o saldo de um sacado real CAI ao confirmar —, então a tabela tem de sair e ser conferida
 * contra a spec antes de qualquer escrita.
 *
 * Uso:
 *   php bin/console app:cobranca:importar-acordos \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/acordos.xlsx             # dry-run
 *   php bin/console app:cobranca:importar-acordos ... --confirmar                          # persiste
 */
#[AsCommand(
    name: 'app:cobranca:importar-acordos',
    description: 'Importa os acordos detalhados (.xlsx): completa parcelas futuras e reconcilia as contas originais',
)]
final class ImportarAcordosDetalhadosCommand extends Command
{
    public function __construct(
        private readonly ImportarAcordosDetalhadosUseCase $importar,
        private readonly AcordosDetalhadosAdapter $adapter,
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
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx de acordos detalhados')
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
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        if ($vinculo === null) {
            $io->error(sprintf('Usuário %d não pertence ao tenant %d.', $usuarioId, $tenantId));

            return Command::FAILURE;
        }

        $leitura = $this->adapter->ler($arquivo);
        $io->section(sprintf('Leitura: %d acordo(s) · %d rejeição(ões) · %d linha(s) ignorada(s)', count($leitura->acordos), count($leitura->rejeitadas), $leitura->linhasIgnoradas));

        foreach ($leitura->rejeitadas as $rejeitada) {
            $io->writeln(sprintf('  <comment>%s</comment> — %s', $rejeitada->referencia, $rejeitada->motivo));
        }

        $resultado = $confirmar
            ? $this->importar->confirmar($carteiraId, $leitura, $tenant, $user)
            : $this->importar->prever($carteiraId, $leitura, $tenant);

        $this->imprimirPorAcordo($io, $resultado, $confirmar);
        $this->imprimirTotais($io, $resultado, $confirmar);
        $this->imprimirAvisos($io, $resultado);

        if (!$confirmar) {
            $io->warning('DRY-RUN: nada foi gravado. Confira a tabela acima — inclusive o saldo que vai CAIR — e repita com --confirmar.');

            return Command::SUCCESS;
        }

        $io->success('Acordos importados.');
        $io->note('O saldo devedor das unidades listadas mudou. Avise a equipe de cobrança antes de emitir novo relatório: relatórios já emitidos passam a discordar do sistema (§8 da spec).');

        return Command::SUCCESS;
    }

    /** A tabela do §1 da spec: por acordo, com unidade e sacado. */
    private function imprimirPorAcordo(SymfonyStyle $io, ResultadoImportacaoAcordos $resultado, bool $confirmar): void
    {
        $io->section($confirmar ? 'Por acordo — o que foi feito' : 'Por acordo — o que aconteceria');

        $linhas = [];
        foreach ($resultado->porAcordo() as $acordo) {
            if ($acordo->foiIgnorado()) {
                $linhas[] = [$acordo->numero, $acordo->unidade, $acordo->sacado, '—', '—', '—', 'IGNORADO'];

                continue;
            }

            $linhas[] = [
                $acordo->numero,
                $acordo->unidade,
                $acordo->sacado,
                sprintf('%d (R$ %s)', count($acordo->parcelasCriadas), $this->reais($acordo->valorParcelasCriadasCentavos)),
                sprintf('%d (R$ %s)', count($acordo->contasMarcadas), $this->reais($acordo->principalReconciliadoCentavos)),
                (string) count($acordo->contasReconstruidas),
                $this->pendencias($acordo),
            ];
        }

        $io->table(
            ['Acordo', 'Unidade', 'Sacado', 'Parcelas a criar', 'Contas a marcar (principal que sai)', 'Contas a reconstruir', 'A conferir'],
            $linhas,
        );

        foreach ($resultado->porAcordo() as $acordo) {
            if ($acordo->foiIgnorado()) {
                $io->writeln(sprintf('  <comment>Acordo %d IGNORADO</comment> — %s', $acordo->numero, (string) $acordo->ignoradoPorque));
            }
        }
    }

    private function imprimirTotais(SymfonyStyle $io, ResultadoImportacaoAcordos $resultado, bool $confirmar): void
    {
        $io->table(
            ['O quê', $confirmar ? 'Feito' : 'Aconteceria'],
            [
                ['Parcelas futuras criadas', sprintf('%d — R$ %s ENTRA no saldo', count($resultado->nnsParcelasCriadas()), $this->reais($resultado->valorParcelasCriadasCentavos()))],
                ['Contas originais marcadas como substituídas (os encargos delas são REESCRITOS na data do acordo)', sprintf('%d — R$ %s de PRINCIPAL sai do saldo (mais os juros/multa/correção que corriam sobre ele)', count($resultado->nnsContasMarcadas()), $this->reais($resultado->principalReconciliadoCentavos()))],
                ['Contas originais reconstruídas (nascem substituídas, não mexem no saldo)', count($resultado->nnsContasReconstruidas())],
                ['Parcelas que já existiam (nada a fazer)', count($resultado->nnsParcelasExistentes())],
                ['Parcelas existentes ligadas ao acordo (não mexe no saldo hoje; evita dívida dupla ao romper)', count($resultado->parcelasVinculadas())],
                ['Contas já marcadas (nada a fazer)', count($resultado->nnsContasJaMarcadas())],
                ['Abas ignoradas', $resultado->totalAbasIgnoradas()],
                ['Linhas rejeitadas na leitura', $resultado->totalRejeitadas()],
            ],
        );
    }

    private function imprimirAvisos(SymfonyStyle $io, ResultadoImportacaoAcordos $resultado): void
    {
        if (!$resultado->temAvisos()) {
            return;
        }

        $io->section('A CONFERIR — a planilha e o sistema discordam nestes pontos');

        $blocos = [
            'Leitura NÃO fecha com o cabeçalho da própria planilha — pode ter faltado linha' => $resultado->conferenciasCabecalho,
            'Divergência de valor (o valor lançado NÃO foi alterado)' => $resultado->divergenciasDeValor(),
            'Linhas recusadas' => $resultado->contasRecusadas(),
            'Parcelas NÃO criadas por NN ambíguo (mesmo NN, outra competência)' => $resultado->parcelasAmbiguas(),
            'Casadas pelo fallback legado — obrigação sem competência gravada, casou só pelo NN' => $resultado->casadasSemCompetencia(),
            'Situação divergente (status do sistema MANTIDO)' => $resultado->situacoesDivergentes(),
            'Situação não reconhecida' => $resultado->situacoesDesconhecidas,
            // A segunda lista é subconjunto da primeira; sem o diff, o mesmo NN apareceria nos dois blocos
            // e o operador contaria duas ocorrências onde há uma.
            'Parcelas que constam LIQUIDADAS na planilha e EXISTEM no sistema — a baixa NÃO foi feita, confira à mão (§5)' => array_values(array_diff($resultado->parcelasLiquidadasNaPlanilha, $resultado->parcelasLiquidadasIgnoradas())),
            'Parcelas pagas que NÃO existem no sistema e NÃO foram criadas (criá-las cobraria de novo)' => $resultado->parcelasLiquidadasIgnoradas(),
        ];

        foreach ($blocos as $titulo => $itens) {
            if ($itens === []) {
                continue;
            }
            $io->writeln(sprintf('<comment>%s:</comment>', $titulo));
            foreach ($itens as $item) {
                $io->writeln('  · ' . $item);
            }
            $io->newLine();
        }
    }

    private function pendencias(AcordoProcessado $acordo): string
    {
        $quantas = count($acordo->divergenciasDeValor)
            + count($acordo->contasRecusadas)
            + count($acordo->parcelasAmbiguas)
            + count($acordo->casadasSemCompetencia)
            + ($acordo->situacaoDivergente !== null ? 1 : 0);

        return $quantas === 0 ? '—' : (string) $quantas;
    }

    private function reais(int $centavos): string
    {
        return number_format($centavos / 100, 2, ',', '.');
    }
}
