<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\ResultadoReconciliacao;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\UseCase\ReconciliarDuplaContagemUseCase;
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
 * Tira do banco o encargo contado duas vezes nas parcelas de acordo (SPEC espelho §17.11).
 *
 * ⚠️ **A ÚNICA peça desta frente que escreve dinheiro.** Simula por padrão; só grava com `--aplicar`
 * **e** `--usuario-id`, porque mudança financeira precisa de autor no histórico.
 *
 * A lista vem da régua (`app:cobranca:espelho:encargos --duplicadas`), que é o artefato que o dono
 * aprova ANTES da escrita — a rede de segurança fica montada antes da faca (§17.8).
 */
#[AsCommand(
    name: 'app:cobranca:reconciliar-dupla-contagem',
    description: 'Corrige o encargo contado duas vezes (SIMULA por padrão; grava só com --aplicar)',
)]
final class ReconciliarDuplaContagemCommand extends Command
{
    /** Mesmo contrato do `espelho:encargos`: significado mora na faixa 6x, `1` é exceção, `2` é INVALID. */
    public const ERRO_DE_INVOCACAO = 64;

    /** A conta não fechou entre a lista da régua e o que foi feito — nada é confiável. */
    public const CONTAS_NAO_FECHAM = 65;

    /** Rodou e não achou nada a corrigir. Não é erro; é "não há o que reconciliar". */
    public const NADA_A_FAZER = 66;

    /** Corrigiu (ou corrigiria) — e há obrigação PULADA, cujo valor inflado permanece. */
    public const SOBROU_INFLACAO = 67;

    public function __construct(
        private readonly ReconciliarDuplaContagemUseCase $reconciliar,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Reconcilia só esta carteira')
            ->addOption(
                'aplicar',
                null,
                InputOption::VALUE_NONE,
                '🔴 GRAVA no banco. Sem esta opção o comando apenas simula.',
            )
            ->addOption(
                'usuario-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Autor da correção no histórico — obrigatório com --aplicar',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return self::ERRO_DE_INVOCACAO;
        }

        $aplicar = (bool) $input->getOption('aplicar');
        $usuarioId = $input->getOption('usuario-id');
        $usuario = $usuarioId === null ? null : $this->em->getRepository(User::class)->find((int) $usuarioId);

        // Mudança financeira precisa de AUTOR. Sem isso o rastro seria só o `AuditLog`, que em CLI sai
        // degradado (sem ator, sem IP, sem rota) — e a correção ficaria órfã no histórico do caso.
        if ($aplicar && $usuario === null) {
            $io->error('--aplicar exige --usuario-id de um usuário existente: a correção precisa de autor.');

            return self::ERRO_DE_INVOCACAO;
        }

        // Guarda multi-tenant: quem assina a correção precisa ser MEMBRO do escritório. O vínculo mora
        // em `UserTenant` (o `User` não carrega tenant), mesma guarda de
        // `ImportarAcordosDetalhadosCommand`.
        if ($aplicar && $this->em->getRepository(UserTenant::class)
                ->findOneBy(['user' => $usuario, 'tenant' => $tenant]) === null) {
            $io->error('O usuário informado não é membro deste escritório.');

            return self::ERRO_DE_INVOCACAO;
        }

        $criterio = ['tenant' => $tenant];
        $carteiraId = $input->getOption('carteira-id');

        if ($carteiraId !== null) {
            $criterio['id'] = (int) $carteiraId;
        }

        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy($criterio);

        if ($carteiras === []) {
            $io->error('Nenhuma carteira casou o critério.');

            return self::ERRO_DE_INVOCACAO;
        }

        $io->title('Reconciliação da dupla contagem de encargo');

        if ($aplicar) {
            $io->warning('MODO --aplicar: isto GRAVA no banco, dentro de uma transação única.');
        } else {
            $io->text('SIMULAÇÃO. Nada é gravado. Use --aplicar (com --usuario-id) para valer.');
        }

        $r = $aplicar
            ? $this->reconciliar->confirmar($carteiras, $tenant, $usuario)
            : $this->reconciliar->prever($carteiras, $tenant);

        return $this->relatar($io, $r);
    }

    private function relatar(SymfonyStyle $io, ResultadoReconciliacao $r): int
    {
        // A conta fecha exata ou o relatório não vale — mesma disciplina da régua.
        if (!$r->contasFecham()) {
            $io->error(sprintf(
                'CONTAS NÃO FECHAM: %d candidatas, %d corrigidas, %d puladas. O relatório não vale.',
                $r->candidatas,
                count($r->corrigidas),
                count($r->puladas),
            ));

            return self::CONTAS_NAO_FECHAM;
        }

        if ($r->candidatas === 0) {
            $io->success('Nenhuma dívida com a assinatura de dupla contagem. Nada a reconciliar.');

            return self::NADA_A_FAZER;
        }

        if ($r->corrigidas !== []) {
            $io->section(sprintf('%s %d dívida(s)', $r->aplicou ? 'CORRIGIDAS:' : 'Seriam corrigidas:', count($r->corrigidas)));

            $io->table(
                ['id', 'unidade', 'referência', 'lote', 'juros', 'multa', 'honorário', 'sai do saldo'],
                array_map(
                    fn (array $c): array => [
                        $c['obrigacaoId'],
                        $c['unidade'],
                        $c['referencia'] ?? '—',
                        sprintf('#%s (%s)', $c['loteId'] ?? '?', $c['loteEmitidoEm']?->format('d/m/Y') ?? '?'),
                        $this->transicao($c['antes']['juros'], $c['depois']['juros']),
                        $this->transicao($c['antes']['multa'], $c['depois']['multa']),
                        $this->transicao($c['antes']['honorarios'], $c['depois']['honorarios']),
                        $this->reais($c['removidoNoSaldo']),
                    ],
                    $r->corrigidas,
                )
            );
        }

        // OS DOIS TOTAIS SEPARADOS, nunca somados numa manchete: o honorário não entra no
        // `valorExigivel()`, logo não muda o que devedor nenhum deve.
        $io->text(sprintf(
            '%s do SALDO do devedor (juros + multa + correção): %s',
            $r->aplicou ? 'SAIU' : 'sairia',
            $this->reais($r->removidoDoSaldoEmCentavos()),
        ));
        $io->text(sprintf(
            '%s FORA do saldo (honorário — não muda o que ninguém deve): %s',
            $r->aplicou ? 'SAIU' : 'sairia',
            $this->reais($r->removidoForaDoSaldoEmCentavos()),
        ));

        if ($r->aplicou) {
            $io->text(sprintf('eventos no histórico: %d caso(s)', $r->casosComEvento));
        }

        // Depois da correção a régua vai marcar estas dívidas como `divergente`, e isso é ESPERADO.
        // Sem esta frase a verificação parece ter dado errado e alguém "desconserta" (decisão do dono).
        $io->note(
            'Depois desta correção a régua (`espelho:encargos`) passa a classificar estas dívidas como '
            . 'DIVERGENTE, e isso é esperado: o gravado passou a ser o número da contabilidade, que a '
            . 'nossa fórmula naquela data não reproduz. Elas saem de "dupla contagem" e caem em '
            . '"divergente" até a próxima hidratação. NÃO é sinal de falha.'
        );

        if ($r->puladas === []) {
            $io->success($r->aplicou ? 'Reconciliação aplicada.' : 'Simulação completa. Nada foi gravado.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'unidade', 'referência', 'motivo', 'inflado que FICA'],
            array_map(
                fn (array $p): array => [
                    $p['obrigacaoId'],
                    $p['unidade'],
                    $p['referencia'] ?? '—',
                    $p['motivo'],
                    $this->reais($p['duplicadoNoSaldo'] + $p['duplicadoForaDoSaldo']),
                ],
                $r->puladas,
            )
        );

        // Pular não é no-op: é valor inflado que permanece. Sai como aviso e com código próprio, senão
        // vira linha de rodapé num relatório que termina em "sucesso".
        $io->warning(sprintf(
            '%d dívida(s) NÃO foram corrigidas e seguem com %s inflados no banco. '
            . 'Congelada nunca é re-hidratada: nelas o valor é permanente até alguém decidir o contrário.',
            count($r->puladas),
            $this->reais($r->inflacaoQueFicouEmCentavos()),
        ));

        return self::SOBROU_INFLACAO;
    }

    private function transicao(int $antes, int $depois): string
    {
        return $antes === $depois
            ? '='
            : sprintf('%s → %s', $this->reais($antes), $this->reais($depois));
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
