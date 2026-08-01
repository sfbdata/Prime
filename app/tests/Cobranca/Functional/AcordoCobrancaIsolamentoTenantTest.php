<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\ParcelaAcordoInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\DTO\RomperAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\AlocacaoPagamento;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Liquidacao;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Exception\AcordoNaoEncontradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\Service\ConversorTaxaEncargo;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\RestauradorObrigacoesOriginais;
use App\Cobranca\Entity\Pagamento;
use App\Cobranca\Exception\AcordoComParcelaPagaException;
use App\Cobranca\UseCase\CancelarAcordoUseCase;
use App\Cobranca\DTO\CancelarAcordoInput;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Cobranca\UseCase\RomperAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Acordos (Etapa 4) contra o BANCO REAL: substituição de obrigações, saldo derivado por STATUS do
 * acordo (substituída sai / parcela entra; rompimento restaura os originais e descarta as parcelas —
 * tudo via `doCasoExigiveis`, invariáveis 15/20), persistência das parcelas (ordem de INSERT com a FK
 * para o acordo) e isolamento multi-tenant (invariáveis 1/13). Roda em KernelTestCase → TenantFilter
 * desligado: prova a guarda EXPLÍCITA dos UseCases, não a rede do filtro.
 */
#[CoversClass(CriarAcordoUseCase::class)]
#[CoversClass(RomperAcordoUseCase::class)]
#[CoversClass(CalculadoraSaldo::class)]
final class AcordoCobrancaIsolamentoTenantTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private AbrirCasoUseCase $abrirCaso;
    private RegistrarObrigacaoUseCase $registrarObrigacao;
    private CriarAcordoUseCase $criarAcordo;
    private RomperAcordoUseCase $romperAcordo;
    private CancelarAcordoUseCase $cancelarAcordo;
    private CalculadoraSaldo $calculadoraSaldo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        /** @var CasoCobrancaRepository $casoRepo */
        $casoRepo = $this->em->getRepository(CasoCobranca::class);
        /** @var ObjetoCobrancaRepository $objetoRepo */
        $objetoRepo = $this->em->getRepository(ObjetoCobranca::class);
        /** @var PessoaRepository $pessoaRepo */
        $pessoaRepo = $this->em->getRepository(Pessoa::class);
        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        /** @var EventoHistoricoRepository $eventoRepo */
        $eventoRepo = $this->em->getRepository(EventoHistorico::class);
        /** @var AcordoRepository $acordoRepo */
        $acordoRepo = $this->em->getRepository(Acordo::class);
        /** @var AlocacaoPagamentoRepository $alocacaoRepo */
        $alocacaoRepo = $this->em->getRepository(AlocacaoPagamento::class);
        /** @var LiquidacaoRepository $liquidacaoRepo */
        $liquidacaoRepo = $this->em->getRepository(Liquidacao::class);

        $registrarEvento = new RegistrarEventoHistorico($eventoRepo);
        $this->abrirCaso = new AbrirCasoUseCase($casoRepo, $objetoRepo, $pessoaRepo, $registrarEvento);
        $this->registrarObrigacao = new RegistrarObrigacaoUseCase($obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos(), new ConversorTaxaEncargo(new CalculadoraEncargos()));
        $this->criarAcordo = new CriarAcordoUseCase($acordoRepo, $obrigacaoRepo, $casoRepo, $registrarEvento, new CalculadoraEncargos(), new ResolvedorConfigEncargos());
        $this->romperAcordo = new RomperAcordoUseCase($acordoRepo, $obrigacaoRepo, new RestauradorObrigacoesOriginais($obrigacaoRepo), $registrarEvento);
        $this->cancelarAcordo = new CancelarAcordoUseCase($acordoRepo, $obrigacaoRepo, $alocacaoRepo, new RestauradorObrigacoesOriginais($obrigacaoRepo), $registrarEvento);
        $this->calculadoraSaldo = new CalculadoraSaldo($obrigacaoRepo, $casoRepo, $alocacaoRepo, $liquidacaoRepo, new \App\Cobranca\Service\EncargosVivos(new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable('2026-07-20')), new \App\Cobranca\Service\CalculadoraEncargos(), new \App\Cobranca\Service\ResolvedorConfigEncargos()), new \App\Cobranca\Service\ResolvedorConfigEncargos());
    }

    #[TestDox('Acordo substitui obrigação (parcial): substituída sai do saldo, parcelas entram; original persiste')]
    public function testAcordoSubstituiEDerivaSaldo(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $o1 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $o2 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 20000), $tenant, $user);
        self::assertSame(30000, $this->calculadoraSaldo->saldoExigivel($caso));

        // Substitui SÓ a O1 (10000) por 2 parcelas (3000 + 4000 = 7000). O2 fica intacta.
        $acordo = $this->criarAcordo->executar(
            $this->acordoInput((int) $caso->getId(), [(int) $o1->getId()], [
                ['1ª parcela', 3000, '2026-06-10'],
                ['2ª parcela', 4000, '2026-07-10'],
            ]),
            $tenant,
            $user,
        );

        self::assertNotNull($acordo->getId());
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus());

        // Saldo agora = O2 (20000) + parcelas (7000); a O1 substituída saiu.
        self::assertSame(27000, $this->calculadoraSaldo->saldoExigivel($caso));

        // Invariável 14: a obrigação substituída PERSISTE (não apagada), marcada com o acordo.
        $this->em->clear();
        /** @var ObrigacaoRepository $obrigacaoRepo */
        $obrigacaoRepo = $this->em->getRepository(Obrigacao::class);
        $o1Recarregada = $obrigacaoRepo->findOneByIdDoTenant((int) $o1->getId(), $tenant);
        self::assertNotNull($o1Recarregada);
        self::assertNotNull($o1Recarregada->getAcordoSubstituto());
    }

    #[TestDox('Romper o acordo restaura as obrigações originais no saldo e descarta as parcelas')]
    public function testRomperAcordoRestauraOriginais(): void
    {
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $o1 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $o2 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 20000), $tenant, $user);

        $acordo = $this->criarAcordo->executar(
            $this->acordoInput((int) $caso->getId(), [(int) $o1->getId(), (int) $o2->getId()], [
                ['Parcela única', 25000, '2026-06-10'],
            ]),
            $tenant,
            $user,
        );
        // O1+O2 (30000) substituídas por 1 parcela de 25000 (renegociação com desconto).
        self::assertSame(25000, $this->calculadoraSaldo->saldoExigivel($caso));

        // Rompe: originais voltam (30000), a parcela sai. Derivação pura, sem reversão imperativa.
        $rompido = $this->romperAcordo->executar($this->romperInput((int) $acordo->getId(), 'Devedor parou de pagar'), $tenant, $user);
        self::assertSame(StatusAcordo::Rompido, $rompido->getStatus());
        self::assertSame(30000, $this->calculadoraSaldo->saldoExigivel($caso));
    }

    #[TestDox('Cancelar o acordo restaura as originais no saldo, sem criar dívida fantasma')]
    public function testCancelarAcordoRestauraOriginaisSemDividaFantasma(): void
    {
        // O teste que a spec §6 elege como decisivo: o saldo depois de cancelar tem de ser EXATAMENTE o
        // de antes do acordo. Nem a mais (parcelas que sobrevivem soltas e passam a somar junto com as
        // originais — foi o que a 1ª versão, que APAGAVA o acordo, teria causado na reimportação), nem
        // a menos (originais que não voltam).
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $o1 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $o2 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 20000), $tenant, $user);
        $saldoAntesDoAcordo = $this->calculadoraSaldo->saldoExigivel($caso);
        self::assertSame(30000, $saldoAntesDoAcordo);

        $acordo = $this->criarAcordo->executar(
            $this->acordoInput((int) $caso->getId(), [(int) $o1->getId(), (int) $o2->getId()], [
                ['Parcela 1/2', 12500, '2026-06-10'],
                ['Parcela 2/2', 12500, '2026-07-10'],
            ]),
            $tenant,
            $user,
        );
        self::assertSame(25000, $this->calculadoraSaldo->saldoExigivel($caso), 'com o acordo vigente, valem as parcelas');

        // Reproduz o estado REAL que o dono encontrou: enquanto estavam substituídas, a migration
        // `Version20260719140000` congelou as originais por engano. Sem congelar aqui, o assert de
        // "voltam vivas" lá embaixo passaria sozinho e não provaria nada — `CriarAcordoUseCase`
        // materializa a substituída SEM congelar.
        $o1->congelarEncargos(new \DateTimeImmutable('2026-07-21 21:04:26'));
        $o2->congelarEncargos(new \DateTimeImmutable('2026-07-21 21:04:26'));
        $this->em->flush();
        self::assertTrue($o1->encargosCongelados(), 'pré-condição: a original entra congelada no cancelamento');

        $cancelado = $this->cancelarAcordo->executar(
            $this->cancelarInput((int) $acordo->getId(), 'Não consta na contabilidade'),
            $tenant,
            $user,
        );

        self::assertSame(StatusAcordo::Cancelado, $cancelado->getStatus());
        self::assertSame(
            $saldoAntesDoAcordo,
            $this->calculadoraSaldo->saldoExigivel($caso),
            'o saldo tem de voltar a ser o de antes do acordo — nem um centavo a mais',
        );

        // E as originais voltam VIVAS: congeladas, elas entrariam no saldo mas parariam de crescer, que
        // é exatamente o defeito que o dono reportou em 01/08.
        $this->em->refresh($o1);
        $this->em->refresh($o2);
        self::assertFalse($o1->encargosCongelados());
        self::assertFalse($o2->encargosCongelados());
    }

    #[TestDox('§D4: acordo com pagamento REAL numa parcela não cancela — com alocação de verdade no banco')]
    public function testCancelarRecusaComPagamentoRealNaParcela(): void
    {
        // O teste unitário desta guarda mocka `existeAlocacaoEmObrigacoes` e, com entidades sem id,
        // exercita o UseCase com uma lista VAZIA — ele passaria mesmo com a guarda quebrada. Aqui a
        // alocação é real, persistida, e quem responde é o repositório de verdade.
        $tenant = $this->criarTenant();
        $user = $this->criarUser();
        $caso = $this->abrirCasoDe($tenant, $user);

        $o1 = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $caso->getId(), 10000), $tenant, $user);
        $acordo = $this->criarAcordo->executar(
            $this->acordoInput((int) $caso->getId(), [(int) $o1->getId()], [['Parcela única', 9000, '2026-06-10']]),
            $tenant,
            $user,
        );

        $parcela = $this->em->getRepository(Obrigacao::class)->findOneBy(['acordoOrigem' => $acordo]);
        self::assertNotNull($parcela);

        $pagamento = (new Pagamento())
            ->setTenant($tenant)
            ->setCaso($caso)
            ->setData(new \DateTimeImmutable('2026-06-11'))
            ->setValorDivida(5000);
        $this->em->persist($pagamento);

        $alocacao = (new AlocacaoPagamento())
            ->setTenant($tenant)
            ->setPagamento($pagamento)
            ->setObrigacao($parcela)
            ->setValor(5000);
        $this->em->persist($alocacao);
        $this->em->flush();

        $this->expectException(AcordoComParcelaPagaException::class);

        try {
            $this->cancelarAcordo->executar(
                $this->cancelarInput((int) $acordo->getId(), 'Engano'),
                $tenant,
                $user,
            );
        } finally {
            // Nada pode ter sido escrito: o acordo continua ativo e a original continua substituída.
            self::assertSame(StatusAcordo::Ativo, $acordo->getStatus());
            self::assertSame($acordo, $o1->getAcordoSubstituto());
        }
    }

    #[TestDox('Criar acordo não alcança caso de outro escritório (IDOR por tenant)')]
    public function testCriarAcordoNaoAlcancaCasoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        $oA = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $casoA->getId(), 10000), $tenantA, $user);

        $this->expectException(CasoNaoEncontradoException::class);

        $this->criarAcordo->executar(
            $this->acordoInput((int) $casoA->getId(), [(int) $oA->getId()], [['P', 5000, '2026-06-10']]),
            $tenantB,
            $user,
        );
    }

    #[TestDox('Romper acordo não alcança acordo de outro escritório (IDOR por tenant)')]
    public function testRomperAcordoNaoAlcancaAcordoDeOutroTenant(): void
    {
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $user = $this->criarUser();
        $casoA = $this->abrirCasoDe($tenantA, $user);
        $oA = $this->registrarObrigacao->executar($this->obrigacaoInput((int) $casoA->getId(), 10000), $tenantA, $user);
        $acordoA = $this->criarAcordo->executar(
            $this->acordoInput((int) $casoA->getId(), [(int) $oA->getId()], [['P', 8000, '2026-06-10']]),
            $tenantA,
            $user,
        );

        $this->expectException(AcordoNaoEncontradoException::class);

        $this->romperAcordo->executar($this->romperInput((int) $acordoA->getId(), 'x'), $tenantB, $user);
    }

    // ----------------------------------------------------------------- helpers

    private function abrirCasoDe(Tenant $tenant, User $user): CasoCobranca
    {
        $objeto = $this->criarObjeto($tenant);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $input = new AbrirCasoInput();
        $input->objetoId = (int) $objeto->getId();
        $input->pessoaCobradaId = (int) $pessoa->getId();

        return $this->abrirCaso->executar($input, $tenant, $user);
    }

    private function obrigacaoInput(int $casoId, int $valorCentavos): RegistrarObrigacaoInput
    {
        $input = new RegistrarObrigacaoInput();
        $input->casoId = $casoId;
        $input->descricao = 'Competência';
        $input->valorOriginal = $valorCentavos;
        $input->vencimentoOriginal = new \DateTimeImmutable('-10 days');

        return $input;
    }

    /**
     * @param int[] $substituidasIds
     * @param array<array{0:string,1:int,2:string}> $parcelas [descricao, valor, vencimento]
     */
    private function acordoInput(int $casoId, array $substituidasIds, array $parcelas): CriarAcordoInput
    {
        $input = new CriarAcordoInput();
        $input->casoId = $casoId;
        $input->dataAcordo = new \DateTimeImmutable('-1 day');
        $input->obrigacoesSubstituidasIds = $substituidasIds;
        $input->parcelas = array_map(function (array $p): ParcelaAcordoInput {
            $parcela = new ParcelaAcordoInput();
            $parcela->descricao = $p[0];
            $parcela->valor = $p[1];
            $parcela->vencimento = new \DateTimeImmutable($p[2]);

            return $parcela;
        }, $parcelas);

        return $input;
    }

    private function cancelarInput(int $acordoId, ?string $motivo): CancelarAcordoInput
    {
        $input = new CancelarAcordoInput();
        $input->acordoId = $acordoId;
        $input->motivo = $motivo;

        return $input;
    }

    private function romperInput(int $acordoId, string $motivo): RomperAcordoInput
    {
        $input = new RomperAcordoInput();
        $input->acordoId = $acordoId;
        $input->motivo = $motivo;

        return $input;
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ACORDO ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function criarUser(): User
    {
        $user = new User();
        $user->setEmail('acordo_' . uniqid() . '@test.com');
        $user->setFullName('User Acordo');
        $user->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function criarObjeto(Tenant $tenant): object
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Multiplo,
        ]);

        return ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
    }
}
