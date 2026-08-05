<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\CadastroCondominosAdapter;
use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use App\Cobranca\UseCase\ImportarCadastroCondominosUseCase;
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
 * Importa o relatório "Dados cadastrais dos condôminos" (.xlsx) da contábil L.G numa Carteira —
 * spec `docs/specs/cobranca-importar-cadastro-condominos.md`.
 *
 * Mesmo contrato do `app:cobranca:importar` (dinheiro): DRY-RUN por padrão, `--confirmar` persiste.
 * Não escreve regra nenhuma — junta o adapter da fonte com o UseCase, que orquestra os UseCases de
 * Pessoa/vínculo/contato já existentes.
 *
 * Uso:
 *   php bin/console app:cobranca:importar-cadastro \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/cadastro.xlsx             # dry-run
 *   php bin/console app:cobranca:importar-cadastro ... --confirmar                          # persiste
 */
#[AsCommand(
    name: 'app:cobranca:importar-cadastro',
    description: 'Importa o cadastro de condôminos (.xlsx) numa carteira: pessoas, vínculos e contatos',
)]
final class ImportarCadastroCondominosCommand extends Command
{
    public function __construct(
        private readonly ImportarCadastroCondominosUseCase $importar,
        private readonly CadastroCondominosAdapter $adapter,
        private readonly ValidadorRodapeFiltros $validadorRodape,
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
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx de cadastro a importar')
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

        if (!$this->recorteConfere($io, $arquivo, RecorteEsperado::cadastro())) {
            return Command::INVALID;
        }

        $leitura = $this->adapter->ler($arquivo);
        $io->section(sprintf('Leitura: %d pessoas · %d rejeições · %d linhas ignoradas', count($leitura->importaveis), count($leitura->rejeitadas), $leitura->linhasIgnoradas));

        $resultado = $confirmar
            ? $this->importar->confirmar($carteiraId, $leitura, $tenant, $user)
            : $this->importar->prever($carteiraId, $leitura, $tenant);

        $io->table(
            ['O quê', $confirmar ? 'Feito' : 'Aconteceria'],
            [
                ['Unidades (objetos) criadas', $resultado->totalObjetosCriados()],
                ['Pessoas criadas', $resultado->totalPessoasCriadas()],
                ['Pessoas já existentes reaproveitadas', $resultado->pessoasReaproveitadas],
                ['Vínculos abertos', $resultado->totalVinculosCriados()],
                ['Telefones acrescentados', $resultado->telefonesAcrescentados],
                ['E-mails acrescentados', $resultado->emailsAcrescentados],
                ['Endereços acrescentados', $resultado->enderecosAcrescentados],
                ['Linhas rejeitadas', $resultado->totalRejeitadas()],
                ['Linhas ignoradas (rodapé/cabeçalho)', $resultado->linhasIgnoradas],
            ],
        );

        if ($resultado->rejeitadas !== []) {
            $io->section('Rejeições (a pessoa pode ter entrado sem o dado recusado)');
            foreach ($resultado->rejeitadas as $rejeitada) {
                $io->writeln(sprintf('  <comment>%s</comment> — %s', $rejeitada->referencia, $rejeitada->motivo));
            }
        }

        if (!$confirmar) {
            $io->warning('DRY-RUN: nada foi gravado. Confira os números acima e repita com --confirmar.');

            return Command::SUCCESS;
        }

        $io->success('Cadastro importado.');

        return Command::SUCCESS;
    }

    /**
     * Recusa o arquivo cujo recorte (linha `Filtros:` do rodapé) não seja o exigido — spec
     * `docs/specs/cobranca-validador-rodape-filtros.md`.
     *
     * Vale nos DOIS modos, e isso é de propósito: um dry-run sobre arquivo errado imprime um relatório
     * convincente e falso, que é pior do que erro nenhum. Por isso a conferência vem ANTES do adapter,
     * não depois.
     */
    private function recorteConfere(SymfonyStyle $io, string $arquivo, RecorteEsperado $esperado): bool
    {
        $rodape = $this->validadorRodape->validar($arquivo, $esperado);
        if ($rodape->aceito) {
            return true;
        }

        $io->error(sprintf('O recorte deste arquivo não serve para "%s". A importação foi RECUSADA.', $esperado->fonte));
        $io->listing($rodape->motivos);
        if ($rodape->linha !== null) {
            $io->writeln('<comment>Rodapé lido no arquivo:</comment>');
            $io->writeln('  ' . $rodape->linha);
        }
        $io->note('Emita o relatório de novo com o recorte correto. Nada foi lido nem gravado.');

        return false;
    }
}
