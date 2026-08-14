<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Importacao\RegistrarEmissaoNaCarteira;
use App\Repository\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grava na carteira a emissão de um relatório JÁ IMPORTADO, **sem importar nada**.
 *
 * Por que existe: a emissão só passou a ser registrada em 07/08/2026, junto com o aviso "dados
 * atualizados até" da tela da carteira. Tudo que entrou antes disso ficou sem data — e a migração só
 * criou a coluna, porque a emissão mora no rodapé do ARQUIVO e não é derivável do banco (medido:
 * apenas 99 obrigações carregam a data no texto, todas do relatório de acordos). Sem este comando, a
 * funcionalidade nasce inerte para todo o dado existente e só se corrige na próxima importação.
 *
 * 🔑 NÃO toca dinheiro: lê o rodapé `Emissão:` do arquivo e grava a data na carteira. Nenhuma
 * obrigação, pagamento ou acordo é lido, criado ou alterado. Por isso não tem dry-run — não há o que
 * prever, e o efeito é uma data num campo de exibição.
 *
 * Uso (um por tipo de relatório; o tipo é o mesmo que a importação usaria):
 *   php bin/console app:cobranca:registrar-emissao \
 *     --tenant-id=1 --carteira-id=1 --tipo=inadimplencia --arquivo=/var/www/relatorio.xlsx
 */
#[AsCommand(
    name: 'app:cobranca:registrar-emissao',
    description: 'Grava a emissão de um relatório já importado na carteira (só a data; não importa nada)',
)]
// Declara NaoLidaComDadoPessoal (INV-Q10): só carimba a data de emissão de um tipo de relatório na
// carteira — não lê, grava nem imprime nome, documento, contato ou identificação de devedor.
final class RegistrarEmissaoRelatorioCommand extends Command implements NaoLidaComDadoPessoal
{
    private const TIPOS = [
        RegistrarEmissaoNaCarteira::CADASTRO,
        RegistrarEmissaoNaCarteira::INADIMPLENCIA,
        RegistrarEmissaoNaCarteira::RECEITAS,
        RegistrarEmissaoNaCarteira::ACORDOS,
    ];

    public function __construct(
        private readonly RegistrarEmissaoNaCarteira $registrarEmissao,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório (tenant) dono da carteira')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'ID da carteira')
            ->addOption('tipo', null, InputOption::VALUE_REQUIRED, 'Tipo do relatório: '.implode(' | ', self::TIPOS))
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx de onde ler o rodapé "Emissão:"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantId = (int) $input->getOption('tenant-id');
        $carteiraId = (int) $input->getOption('carteira-id');
        $tipo = (string) $input->getOption('tipo');
        $arquivo = (string) $input->getOption('arquivo');

        if ($tenantId <= 0 || $carteiraId <= 0 || $arquivo === '') {
            $io->error('Informe --tenant-id, --carteira-id e --arquivo.');

            return Command::INVALID;
        }

        if (!\in_array($tipo, self::TIPOS, true)) {
            $io->error(sprintf('Tipo inválido "%s". Use um de: %s.', $tipo, implode(', ', self::TIPOS)));

            return Command::INVALID;
        }

        if (!is_readable($arquivo)) {
            $io->error(sprintf('Arquivo não encontrado ou ilegível: %s', $arquivo));

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant %d não encontrado.', $tenantId));

            return Command::INVALID;
        }

        $emissao = $this->registrarEmissao->registrar($carteiraId, $tenant, $tipo, $arquivo);

        // `registrar` engole a própria falha de propósito (numa importação, uma data ilegível não pode
        // derrubar 8 mil recebimentos). Aqui a data É o trabalho: null tem de virar erro visível, senão
        // o comando mente dizendo que gravou.
        if ($emissao === null) {
            $io->error(sprintf(
                'Não foi possível ler a emissão de %s (rodapé "Emissão:" ausente/ilegível) ou a carteira %d não existe neste tenant. Nada foi gravado.',
                basename($arquivo),
                $carteiraId,
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Carteira %d · %s: emissão registrada em %s.',
            $carteiraId,
            $tipo,
            $emissao->format('d/m/Y H:i'),
        ));

        return Command::SUCCESS;
    }
}
