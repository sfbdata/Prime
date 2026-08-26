<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ReconciliarHonorarioDeParcelaCommand;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\UseCase\ImportarAcordosDetalhadosUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * As TRAVAS do comando que escreve dinheiro — spec `cobranca-honorario-no-total.md` §10.5.2.
 *
 * Existe porque a 4ª revisão apontou o mesmo óbvio que já tinha pegado o comando irmão: as guardas
 * (autor obrigatório, autor do escritório certo, lista aprovada, meia-trava recusada) estavam
 * escritas e **sem uma linha de prova**. São elas que separam "o dono aprovou estas 135" de "o
 * comando escreveu sobre outra coisa".
 */
final class ReconciliarHonorarioDeParcelaCommandTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private CommandTester $comando;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $aplicacao = new Application(self::$kernel);
        $this->comando = new CommandTester($aplicacao->find('app:cobranca:reconciliar-honorario-parcela'));
    }

    #[TestDox('🔴 sem --aplicar, SIMULA: nada é gravado e a linha com os ids sai pronta')]
    public function testSimulaPorPadrao(): void
    {
        [$carteira, $tenant, , $parcela] = $this->cenario();

        $codigo = $this->comando->execute(['--tenant-id' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        $saida = $this->comando->getDisplay();
        self::assertStringContainsString('SIMULAÇÃO', $saida);
        self::assertStringContainsString('--ids=' . $parcela->getId(), $saida, 'a linha para aplicar tem de vir pronta para colar');

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp(), 'simulação NÃO grava');
    }

    /**
     * 🔴 INV-H4, achado da 8ª revisão. O override que este comando grava é PERMANENTE: numa dívida que
     * está DENTRO do exigível ele desliga para sempre um honorário que a contabilidade volta a cobrar
     * quando a parcela atrasa e migra para o relatório de inadimplência (spec §10.8). Oferecer as duas
     * na mesma linha punha o caminho de menor esforço — copiar a linha inteira — a serviço do pior
     * resultado possível.
     *
     * ⚠️ A asserção compara a lista de ids por IGUALDADE EXATA, não por `assertStringContainsString`.
     * Com ids 1 e 12, a saída `--ids=12` CONTÉM `--ids=1` — a asserção "está lá" passaria com a lista
     * errada. Esse é o defeito que já passou por duas revisões nesta base.
     */
    #[TestDox('🔴 INV-H4: candidata DENTRO do exigível fica fora da linha pronta para colar')]
    public function testCandidataDentroDoExigivelNaoEntraNaLinhaProntaParaColar(): void
    {
        [, $tenant, , $segura] = $this->cenario();
        $viva = $this->parcelaDentroDoExigivel($segura);

        $codigo = $this->comando->execute(['--tenant-id' => (string) $tenant->getId()]);

        self::assertSame(Command::SUCCESS, $codigo);
        $saida = $this->comando->getDisplay();

        self::assertSame(1, preg_match('/--ids=([\d,]+)/', $saida, $m), 'a linha para colar tem de existir');
        self::assertSame(
            (string) $segura->getId(),
            $m[1],
            'só a de FORA do exigível pode ir na linha pronta para colar',
        );

        // A de dentro não some: sai com aviso, para o humano poder incluí-la à mão se quiser.
        self::assertStringContainsString('DENTRO do exigível', $saida);

        // ⚠️ Aqui havia `assertStringContainsString((string) $viva->getId(), $saida)` — o mesmo defeito
        // que o docblock acima se gaba de evitar: um id curto casa dentro de qualquer outro número da
        // saída (id 12 "está contido" em 1200, em 4512, na competência...). A asserção passava sempre.
        // Agora ela mira a LINHA da tabela de avisos: a coluna do NN é única daquela obrigação.
        self::assertMatchesRegularExpression(
            '/^\s*' . $viva->getId() . '\s.*\s60377\s/m',
            $saida,
            'a candidata de dentro do exigível tem de sair na tabela de aviso, com id e NN na mesma linha',
        );
    }

    /**
     * O outro lado do INV-H4: a trava é sobre o que o comando SUGERE, nunca sobre o que ele aceita.
     * Se o humano conferiu a obrigação na planilha e digitou o id, ele manda — é a §1.1 aplicada ao
     * comando ("o sistema mostra, a gerência julga"). Sem este teste, alguém "endureceria" a trava
     * recusando o id e transformaria um aviso numa proibição, sem ninguém notar.
     */
    #[TestDox('🔴 INV-H4: id de dentro do exigível AINDA é aceito quando o humano o digita')]
    public function testCandidataDentroDoExigivelEhAceitaQuandoOHumanoADigita(): void
    {
        [, $tenant, $user, $segura] = $this->cenario();
        $viva = $this->parcelaDentroDoExigivel($segura);

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => (string) $viva->getId(),
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::SOBROU_HONORARIO, $codigo, 'a segura ficou de fora, então sobra honorário');

        $this->em->clear();
        self::assertSame(0, $this->recarregar($viva)?->getTaxaHonorariosBp(), 'o id digitado foi corrigido');
        self::assertNull($this->recarregar($segura)?->getTaxaHonorariosBp(), 'a que não foi digitada não muda');
    }

    #[TestDox('🔴 --aplicar SEM --ids é recusado: a varredura propõe, o humano escolhe (INV-H0)')]
    public function testAplicarSemIdsEhRecusado(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::SEM_LISTA_APROVADA, $codigo);

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp(), 'nada pode ser gravado sem lista aprovada');
    }

    #[TestDox('🔴 --aplicar sem --usuario-id é recusado: mudança de dinheiro precisa de autor')]
    public function testAplicarSemAutorEhRecusado(): void
    {
        [, $tenant, , $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--ids' => (string) $parcela->getId(),
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::ERRO_DE_INVOCACAO, $codigo);

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp());
    }

    #[TestDox('🔴 autor de OUTRO escritório é recusado (guarda multi-tenant)')]
    public function testAutorDeOutroEscritorioEhRecusado(): void
    {
        [, $tenant, , $parcela] = $this->cenario();

        /** @var User $estranho */
        $estranho = UserFactory::createOne()->_real();
        $this->em->persist(new UserTenant($estranho, TenantFactory::createOne()->_real()));
        $this->em->flush();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $estranho->getId(),
            '--ids' => (string) $parcela->getId(),
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::ERRO_DE_INVOCACAO, $codigo);

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp());
    }

    #[TestDox('🔴 meia-trava (--esperado-dividas sem --esperado-total) é recusada')]
    public function testMeiaTravaEhRecusada(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => (string) $parcela->getId(),
            '--esperado-dividas' => '1',
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::ERRO_DE_INVOCACAO, $codigo);

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp());
    }

    #[TestDox('🔴 id aprovado que não é candidato aborta com 68 e NÃO grava')]
    public function testIdInexistenteAborta(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => $parcela->getId() . ',999999',
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::LISTA_MUDOU, $codigo);

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp(), 'ou tudo, ou nada');
    }

    /**
     * 🔴 Achado da 5ª revisão. `(int)` coage em silêncio: `--ids=12x` virava `[12]` e a obrigação 12
     * seria escrita SEM ter sido aprovada — a violação literal do INV-H0, por um dígito trocado numa
     * cola de terminal.
     */
    #[TestDox('🔴 --ids malformado é recusado, nunca coagido para outro id')]
    public function testIdsMalformadoEhRecusado(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => $parcela->getId() . 'x',
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::ERRO_DE_INVOCACAO, $codigo);
        self::assertStringContainsString('Não entendi', $this->comando->getDisplay());

        $this->em->clear();
        self::assertNull($this->recarregar($parcela)?->getTaxaHonorariosBp(), 'um dígito trocado NÃO pode virar aprovação de outra obrigação');
    }

    #[TestDox('O ciclo completo: simular, TIRAR uma linha da lista, aplicar só o que sobrou')]
    public function testCicloCompletoComRecusaDeUmaLinha(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();
        $segunda = $this->outraParcela($parcela);

        $this->comando->execute(['--tenant-id' => (string) $tenant->getId()]);
        self::assertStringContainsString('--ids=', $this->comando->getDisplay());

        // O dono confere contra a planilha e RECUSA a segunda: tira o id da linha.
        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => (string) $parcela->getId(),
        ]);

        self::assertSame(ReconciliarHonorarioDeParcelaCommand::SOBROU_HONORARIO, $codigo, 'sobrou obrigação não corrigida — o código tem de dizer isso');

        $this->em->clear();
        self::assertSame(0, $this->recarregar($parcela)?->getTaxaHonorariosBp());
        self::assertNull($this->recarregar($segunda)?->getTaxaHonorariosBp(), 'a recusada não pode ter sido tocada');
    }

    #[TestDox('Escritório inexistente é recusado antes de qualquer leitura')]
    public function testTenantInexistente(): void
    {
        self::assertSame(
            ReconciliarHonorarioDeParcelaCommand::ERRO_DE_INVOCACAO,
            $this->comando->execute(['--tenant-id' => '999999']),
        );
    }

    #[TestDox('✅ o caminho feliz: com autor e lista aprovada, grava e reporta')]
    public function testAplicaComListaAprovada(): void
    {
        [, $tenant, $user, $parcela] = $this->cenario();

        $codigo = $this->comando->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--aplicar' => true,
            '--usuario-id' => (string) $user->getId(),
            '--ids' => (string) $parcela->getId(),
        ]);

        self::assertSame(Command::SUCCESS, $codigo);

        $this->em->clear();
        $depois = $this->recarregar($parcela);
        self::assertSame(0, $depois?->getTaxaHonorariosBp());
        self::assertSame(0, $depois?->getHonorarios());
    }

    // ---------------------------------------------------------------------------------------------

    /** Uma segunda candidata no mesmo caso, para o ciclo de recusa parcial. */
    private function outraParcela(Obrigacao $modelo): Obrigacao
    {
        $caso = $modelo->getCaso();
        self::assertNotNull($caso);

        /** @var Obrigacao $outra */
        $outra = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'descricao' => '1.1 - Taxa de condomínio — competência 02/2026 | '
                . ImportarAcordosDetalhadosUseCase::MARCA_RECONSTRUIDA . ' (emissão 29/07/2026)',
            'referenciaExterna' => '60240',
            'competencia' => '02/2026',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-02-13'),
        ])->_real();

        $outra->setAcordoOrigem($this->acordo($caso));
        $outra->setAcordoSubstituto($this->acordo($caso));
        $outra->definirEncargos(900, 260, 0, 2900, new \DateTimeImmutable('2026-06-30'));
        $this->em->flush();

        return $outra;
    }

    /**
     * Uma candidata que está DENTRO do exigível (INV-H4): tem `acordoOrigem` vigente — logo é parcela e
     * a consulta a encontra — mas **não tem acordo substituto**, e é o substituto vigente que tira a
     * obrigação do saldo em `ObrigacaoRepository::aplicarExigibilidade`.
     *
     * É exatamente a forma das 114 parcelas que a contabilidade cobra na inadimplência dela (spec
     * §10.8): nenhuma delas tem substituto. Em produção, nenhuma das 135 candidatas está nesta
     * condição — este cenário existe para o dia em que uma estiver.
     */
    private function parcelaDentroDoExigivel(Obrigacao $modelo): Obrigacao
    {
        $caso = $modelo->getCaso();
        self::assertNotNull($caso);

        /** @var Obrigacao $viva */
        $viva = ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'descricao' => '1.1 - Taxa de condomínio — competência 03/2026',
            'referenciaExterna' => '60377',
            'competencia' => '03/2026',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-03-13'),
        ])->_real();

        $viva->setAcordoOrigem($this->acordo($caso));
        $viva->definirEncargos(900, 260, 0, 2900, new \DateTimeImmutable('2026-06-30'));
        $this->em->flush();

        self::assertNull($viva->getAcordoSubstituto(), 'sem substituto é o que a deixa DENTRO do exigível');

        return $viva;
    }

    private function recarregar(Obrigacao $o): ?Obrigacao
    {
        return $this->em->getRepository(Obrigacao::class)->find($o->getId());
    }

    /** @return array{0: Carteira, 1: Tenant, 2: User, 3: Obrigacao} */
    private function cenario(): array
    {
        /** @var Carteira $carteira */
        $carteira = CarteiraFactory::createOne([
            'tenant' => TenantFactory::createOne(),
            'taxaJurosMensalBp' => 100,
            'regimeJuros' => RegimeJuros::Simples,
            'taxaMultaBp' => 200,
            'baseMulta' => BaseEncargo::Principal,
            'taxaCorrecaoBp' => 0,
            'baseCorrecao' => BaseEncargo::Principal,
            'baseHonorarios' => BaseEncargo::Composta,
            'formaHonorarios' => FormaHonorarios::AcrescidoDivida,
            'percentualHonorarios' => '20.00',
            'carenciaHonorariosDias' => 30,
            'toleranciaJurosMultaDias' => 0,
        ])->_real();

        $tenant = $carteira->getTenant();
        self::assertNotNull($tenant);

        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'carteira' => $carteira,
            'identificacao' => 'QD 05 CH 03',
        ]);

        /** @var CasoCobranca $caso */
        $caso = CasoCobrancaFactory::createOne(['tenant' => $tenant, 'objeto' => $objeto])->_real();

        /** @var Obrigacao $parcela */
        $parcela = ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => '1.1 - Taxa de condomínio — competência 01/2026 | '
                . ImportarAcordosDetalhadosUseCase::MARCA_RECONSTRUIDA . ' (emissão 29/07/2026)',
            'referenciaExterna' => '60049',
            'competencia' => '01/2026',
            'valorOriginal' => 17000,
            'vencimentoOriginal' => new \DateTimeImmutable('2026-01-13'),
        ])->_real();

        $parcela->setAcordoOrigem($this->acordo($caso));
        $parcela->setAcordoSubstituto($this->acordo($caso));
        $parcela->definirEncargos(1200, 340, 0, 3658, new \DateTimeImmutable('2026-06-30'));

        /** @var User $user */
        $user = UserFactory::createOne()->_real();
        $this->em->persist(new UserTenant($user, $tenant));
        $this->em->flush();

        return [$carteira, $tenant, $user, $parcela];
    }

    private function acordo(CasoCobranca $caso): Acordo
    {
        /** @var Acordo $acordo */
        $acordo = AcordoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'status' => StatusAcordo::Cumprido,
            'dataAcordo' => new \DateTimeImmutable('2026-06-30'),
        ])->_real();

        return $acordo;
    }
}
