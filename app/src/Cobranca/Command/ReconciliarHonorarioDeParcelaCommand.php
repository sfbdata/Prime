<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\ExpectativaDaLista;
use App\Cobranca\DTO\ResultadoReconciliacaoHonorario;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Exception\UniversoDaListaMudouException;
use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\UseCase\ReconciliarHonorarioDeParcelaUseCase;
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
 * Tira o honorário que a cascata cobrou por cima das PARCELAS DE ACORDO que ficaram sem o override —
 * spec `docs/specs/cobranca-honorario-no-total.md` §10.
 *
 * ⚠️ **Escreve dinheiro.** Simula por padrão; só grava com `--aplicar` **e** `--usuario-id`, porque
 * mudança financeira precisa de autor no histórico.
 *
 * O artefato que o dono aprova é a SIMULAÇÃO: ela lista obrigação por obrigação, com o número do
 * acordo de origem e o do acordo que a substituiu, para dar para conferir contra a planilha dela.
 * ⚠️ A coluna de valor é o que está NO SISTEMA — é justamente esse número que quem confere vai
 * procurar na planilha. Rotulá-la "valor da planilha" pré-responderia a pergunta.
 */
#[AsCommand(
    name: 'app:cobranca:reconciliar-honorario-parcela',
    description: 'Zera o honorário cobrado por cima da parcela de acordo (SIMULA por padrão; grava só com --aplicar)',
)]
final class ReconciliarHonorarioDeParcelaCommand extends Command implements LidaComDadoPessoal
{
    /** Mesmo contrato dos comandos do espelho: significado na faixa 6x, `1` é exceção, `2` é INVALID. */
    public const ERRO_DE_INVOCACAO = 64;

    /** O universo encontrado não fecha com corrigidas + puladas — nada é confiável. */
    public const CONTAS_NAO_FECHAM = 65;

    /** Rodou e não achou nada. Não é erro: é "não há o que corrigir". */
    public const NADA_A_FAZER = 66;

    /** Corrigiu (ou corrigiria) — e sobrou obrigação PULADA, cujo honorário indevido permanece. */
    public const SOBROU_HONORARIO = 67;

    /** 🔴 A lista mudou entre a aprovação e a escrita. Nada foi gravado. */
    public const LISTA_MUDOU = 68;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly ReconciliarHonorarioDeParcelaUseCase $reconciliar,
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
                'Roda mesmo com o log de SQL ligado. A saída conterá dado pessoal.',
            )
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Corrige só esta carteira')
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
            )
            ->addOption(
                'esperado-dividas',
                null,
                InputOption::VALUE_REQUIRED,
                'OPCIONAL — quantas obrigações a lista aprovada tinha; aborta se não bater',
            )
            ->addOption(
                'esperado-total',
                null,
                InputOption::VALUE_REQUIRED,
                'OPCIONAL — honorário indevido (em CENTAVOS) da lista aprovada; aborta se não bater',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 🔴 INV-Q10: com o log de SQL ligado os parâmetros das consultas — dado pessoal por extenso —
        // saem na tela, numa saída que o dono cola em chat.
        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:reconciliar-honorario-parcela')) {
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

        // Mudança financeira precisa de AUTOR: em CLI o `AuditLog` sai degradado (sem ator, sem IP, sem
        // rota) e a correção ficaria órfã no histórico do caso.
        if ($aplicar && $usuario === null) {
            $io->error('--aplicar exige --usuario-id de um usuário existente: a correção precisa de autor.');

            return self::ERRO_DE_INVOCACAO;
        }

        // Guarda multi-tenant: quem assina a correção precisa ser MEMBRO do escritório. O vínculo mora
        // em `UserTenant` — o `User` não carrega tenant.
        if ($aplicar && $this->em->getRepository(UserTenant::class)
                ->findOneBy(['user' => $usuario, 'tenant' => $tenant]) === null) {
            $io->error('O usuário informado não é membro deste escritório.');

            return self::ERRO_DE_INVOCACAO;
        }

        $esperadoDividas = $input->getOption('esperado-dividas');
        $esperadoTotal = $input->getOption('esperado-total');

        // As duas andam JUNTAS: uma sozinha travaria metade da lista e passaria a outra metade em
        // silêncio — pior do que não travar, porque quem informou uma acha que travou.
        if (($esperadoDividas === null) !== ($esperadoTotal === null)) {
            $io->error('--esperado-dividas e --esperado-total andam juntos: informe os dois ou nenhum.');

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

        $io->title('Honorário cobrado por cima da parcela de acordo');

        if ($aplicar) {
            $io->warning('MODO --aplicar: isto GRAVA no banco, dentro de uma transação única.');
        } else {
            $io->text('SIMULAÇÃO. Nada é gravado. Use --aplicar (com --usuario-id) para valer.');
        }

        try {
            $r = $aplicar
                ? $this->reconciliar->confirmar(
                    $carteiras,
                    $tenant,
                    $usuario,
                    $esperadoDividas === null
                        ? null
                        : new ExpectativaDaLista((int) $esperadoDividas, (int) $esperadoTotal),
                )
                : $this->reconciliar->prever($carteiras, $tenant);
        } catch (UniversoDaListaMudouException $e) {
            $io->error($e->getMessage());

            return self::LISTA_MUDOU;
        }

        return $this->relatar($io, $r);
    }

    private function relatar(SymfonyStyle $io, ResultadoReconciliacaoHonorario $r): int
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
            $io->success('Nenhuma parcela de acordo sem o override de honorário. Nada a corrigir.');

            return self::NADA_A_FAZER;
        }

        if ($r->corrigidas !== []) {
            $io->section(sprintf('%s %d obrigação(ões)', $r->aplicou ? 'CORRIGIDAS:' : 'Seriam corrigidas:', count($r->corrigidas)));

            $io->table(
                ['id', 'unidade', 'NN', 'compet.', 'valor NO SISTEMA', 'honorário retirado', 'acordo (parcela de)', 'substituída por'],
                array_map(
                    fn (array $c): array => [
                        $c['obrigacaoId'],
                        $c['unidade'],
                        $c['referencia'] ?? '—',
                        $c['competencia'] ?? '—',
                        $this->reais($c['valorOriginal']),
                        $this->reais($c['honorarioRemovido']),
                        $c['acordoOrigem'] ?? '—',
                        sprintf('%s%s', $c['acordoSubstituto'] ?? '—', $c['foraDoExigivel'] ? ' (fora do saldo)' : ''),
                    ],
                    $r->corrigidas,
                )
            );
        }

        $io->text(sprintf(
            'honorário que %s do campo materializado: %s',
            $r->aplicou ? 'SAIU' : 'sairia',
            $this->reais($r->honorarioRemovidoEmCentavos()),
        ));

        // 🔑 O recorte que impede a manchete errada. Obrigação com acordo substituto VIGENTE está FORA
        // do exigível (`aplicarExigibilidade`), então corrigi-la NÃO muda o que ninguém deve hoje — muda
        // a tela do acordo, que lista as parcelas sem esse filtro. Sem esta linha, o número de cima
        // seria lido como "a dívida baixou", que é falso.
        $presas = $r->corrigidasForaDoExigivel();

        $io->text(sprintf(
            'dessas, %d estão FORA do exigível (substituída por acordo vigente, ou parcela de acordo '
            . 'rompido): a correção arruma a ficha e a '
            . 'tela do acordo, e NÃO muda o saldo de ninguém hoje. As outras %d mudam o saldo.',
            $presas,
            count($r->corrigidas) - $presas,
        ));

        if ($r->aplicou) {
            $io->text(sprintf('eventos no histórico: %d caso(s)', $r->casosComEvento));
        } else {
            // Fecha o ciclo: simular, aprovar, e colar de volta o que foi aprovado.
            $io->section('Para aplicar');
            $io->writeln('  --aplicar --usuario-id=<id>');
            $io->writeln('');
            $io->text('Ou, travando nesta lista exata (opcional — aborta com 68 se ela mudar até lá):');
            $io->writeln(sprintf(
                '  --aplicar --usuario-id=<id> --esperado-dividas=%d --esperado-total=%d',
                $r->candidatas,
                $r->honorarioRemovidoEmCentavos() + $r->honorarioQueFicouEmCentavos(),
            ));
        }

        if ($r->puladas === []) {
            $io->success($r->aplicou ? 'Correção aplicada.' : 'Simulação completa. Nada foi gravado.');

            return Command::SUCCESS;
        }

        $io->table(
            ['id', 'unidade', 'NN', 'motivo', 'honorário que FICA'],
            array_map(
                fn (array $p): array => [
                    $p['obrigacaoId'],
                    $p['unidade'],
                    $p['referencia'] ?? '—',
                    $p['motivo'],
                    $this->reais($p['honorarioQueFicou']),
                ],
                $r->puladas,
            )
        );

        // Pular não é no-op: é honorário indevido que permanece. Sai como aviso e com código próprio,
        // senão vira linha de rodapé num relatório que termina em "sucesso".
        $io->warning(sprintf(
            '%d obrigação(ões) NÃO foram corrigidas e seguem com %s de honorário indevido no banco. '
            . 'Congelada nunca é re-hidratada: nelas o valor é permanente até alguém decidir o contrário.',
            count($r->puladas),
            $this->reais($r->honorarioQueFicouEmCentavos()),
        ));

        return self::SOBROU_HONORARIO;
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
