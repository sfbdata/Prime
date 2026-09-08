<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\UseCase\CorrigirPastaJudicialDuplicadaUseCase;
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
 * Corrige o Problema B (pasta judicial vinculada a mais de um `CasoCobranca` — medido em produção:
 * mesma pessoa em unidades diferentes, e pessoas diferentes na mesma pasta). Simula por padrão; só
 * grava com `--aplicar` e `--usuario-id`, no mesmo molde de `app:cobranca:reconciliar-dupla-contagem`.
 */
#[AsCommand(
    name: 'app:cobranca:corrigir-pasta-judicial-duplicada',
    description: 'Corrige pasta judicial vinculada a mais de um caso (SIMULA por padrão; grava só com --aplicar)',
)]
final class CorrigirPastaJudicialDuplicadaCommand extends Command implements LidaComDadoPessoal
{
    public const ERRO_DE_INVOCACAO = 64;
    public const NADA_A_FAZER = 66;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly CorrigirPastaJudicialDuplicadaUseCase $corrigir,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aceito-log-com-pii', null, InputOption::VALUE_NONE, 'Roda mesmo com o log de SQL ligado. A saída conterá dado pessoal.')
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('aplicar', null, InputOption::VALUE_NONE, '🔴 GRAVA no banco. Sem esta opção o comando apenas simula.')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'Autor da correção no histórico — obrigatório com --aplicar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:corrigir-pasta-judicial-duplicada')) {
            return GuardaDeLogComPii::LOG_COM_PII;
        }

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return self::ERRO_DE_INVOCACAO;
        }

        $aplicar = (bool) $input->getOption('aplicar');
        $usuarioId = $input->getOption('usuario-id');
        $usuario = $usuarioId === null ? null : $this->em->getRepository(User::class)->find((int) $usuarioId);

        if ($aplicar && $usuario === null) {
            $io->error('--aplicar exige --usuario-id de um usuário existente: a correção precisa de autor.');

            return self::ERRO_DE_INVOCACAO;
        }

        if ($aplicar && $this->em->getRepository(UserTenant::class)
                ->findOneBy(['user' => $usuario, 'tenant' => $tenant]) === null) {
            $io->error('O usuário informado não é membro deste escritório.');

            return self::ERRO_DE_INVOCACAO;
        }

        $io->title('Correção de pasta judicial duplicada');

        if ($aplicar) {
            $io->warning('MODO --aplicar: isto GRAVA no banco, dentro de uma transação única.');
        } else {
            $io->text('SIMULAÇÃO. Nada é gravado. Use --aplicar (com --usuario-id) para valer.');
        }

        $r = $aplicar ? $this->corrigir->confirmar($tenant, $usuario) : $this->corrigir->prever($tenant);

        if ($r->itens === []) {
            $io->success('Nenhuma pasta judicial vinculada a mais de um caso. Nada a corrigir.');

            return self::NADA_A_FAZER;
        }

        $io->table(
            ['pasta antiga', 'caso vencedor', 'caso corrigido', 'unidade', 'pessoa', 'pasta nova', 'reaproveitou órfã?'],
            array_map(
                static fn (array $i): array => [
                    $i['pastaAntigaNup'] ?? '#' . $i['pastaAntigaId'],
                    '#' . $i['casoVencedorId'],
                    '#' . $i['casoCorrigidoId'],
                    $i['casoCorrigidoUnidade'],
                    $i['casoCorrigidoPessoa'],
                    $i['pastaNovaNup'] ?? ($i['pastaNovaId'] !== null ? '#' . $i['pastaNovaId'] : '(seria criada)'),
                    $i['reaproveitouOrfa'] ? 'sim' : 'não',
                ],
                $r->itens,
            ),
        );

        $io->success(sprintf(
            '%s %d caso(s).',
            $r->aplicou ? 'Corrigidos' : 'Seriam corrigidos',
            count($r->itens),
        ));

        if (!$r->aplicou) {
            $io->section('Para aplicar');
            $io->writeln('  --aplicar --usuario-id=<id>');
        }

        return Command::SUCCESS;
    }
}
