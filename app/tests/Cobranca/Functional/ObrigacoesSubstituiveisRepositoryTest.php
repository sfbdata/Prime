<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\AcordoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Contrato de `doCasoSubstituiveis` contra o BANCO REAL (ajuste 9, INV-I): um acordo só substitui DÍVIDA
 * ORIGINAL. O método é a lista que a tela de criar acordo oferece — e é um SUBCONJUNTO estrito do exigível,
 * nunca uma regra paralela de saldo (INV-J: `doCasoExigiveis` fica intacto e segue devolvendo as parcelas).
 *
 * O grafo cobre as três origens possíveis de uma obrigação: original avulsa, original já substituída por
 * acordo vigente, e parcela gerada por acordo (vigente e não-vigente).
 */
#[CoversClass(ObrigacaoRepository::class)]
final class ObrigacoesSubstituiveisRepositoryTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private ObrigacaoRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var ObrigacaoRepository $repo */
        $repo = $this->em->getRepository(Obrigacao::class);
        $this->repo = $repo;
    }

    #[Test]
    #[TestDox('Só as dívidas ORIGINAIS são substituíveis: parcela de acordo vigente fica de fora')]
    public function parcelaDeAcordoVigenteNaoEhSubstituivel(): void
    {
        $tenant = $this->tenant();
        $caso = $this->caso($tenant);

        // Dívida original avulsa: nunca tocada por acordo — é o único alvo legítimo de um acordo novo.
        $original = $this->obrigacao($tenant, $caso, 'Original avulsa', '2026-01-10');

        // Acordo A VIGENTE: substituiu uma original e gerou duas parcelas.
        $acordoVigente = $this->acordo($tenant, $caso, StatusAcordo::Ativo);
        $substituida = $this->obrigacao($tenant, $caso, 'Original substituída', '2026-02-10');
        $substituida->setAcordoSubstituto($acordoVigente);
        $parcela1 = $this->obrigacao($tenant, $caso, 'Parcela 1/2', '2026-03-10');
        $parcela1->setAcordoOrigem($acordoVigente);
        $parcela2 = $this->obrigacao($tenant, $caso, 'Parcela 2/2', '2026-04-10');
        $parcela2->setAcordoOrigem($acordoVigente);

        $this->em->flush();

        $exigiveis = $this->repo->doCasoExigiveis($caso);
        $substituiveis = $this->repo->doCasoSubstituiveis($caso);

        // INV-J: o exigível (fonte do saldo) NÃO muda — a parcela de acordo vigente continua cobrável.
        self::assertSame(
            ['Original avulsa', 'Parcela 1/2', 'Parcela 2/2'],
            $this->descricoes($exigiveis),
            'doCasoExigiveis deve seguir devolvendo as parcelas do acordo vigente (fonte do saldo).',
        );

        // INV-I: só a original avulsa pode ser substituída por um acordo novo.
        self::assertSame(
            ['Original avulsa'],
            $this->descricoes($substituiveis),
            'Parcela de acordo vigente não pode ser oferecida como substituível (acordo sobre acordo).',
        );
    }

    #[Test]
    #[TestDox('Rompido o acordo, a original volta a ser substituível e a parcela órfã não entra')]
    public function acordoRompidoDevolveOriginalENaoOfereceParcela(): void
    {
        $tenant = $this->tenant();
        $caso = $this->caso($tenant);

        // Acordo R ROMPIDO: a original que ele substituiu volta ao exigível; suas parcelas viram histórico.
        $acordoRompido = $this->acordo($tenant, $caso, StatusAcordo::Rompido);
        $original = $this->obrigacao($tenant, $caso, 'Original devolvida', '2026-01-10');
        $original->setAcordoSubstituto($acordoRompido);
        $parcelaOrfa = $this->obrigacao($tenant, $caso, 'Parcela órfã', '2026-02-10');
        $parcelaOrfa->setAcordoOrigem($acordoRompido);

        $this->em->flush();

        // A original volta (é o caminho legítimo de renegociar: romper e acordar de novo).
        self::assertSame(['Original devolvida'], $this->descricoes($this->repo->doCasoExigiveis($caso)));
        self::assertSame(
            ['Original devolvida'],
            $this->descricoes($this->repo->doCasoSubstituiveis($caso)),
            'Após romper o acordo, a dívida original volta a ser acordável.',
        );
    }

    #[Test]
    #[TestDox('Substituíveis é sempre um subconjunto do exigível (nunca uma regra paralela)')]
    public function substituiveisEhSubconjuntoDoExigivel(): void
    {
        $tenant = $this->tenant();
        $caso = $this->caso($tenant);

        $this->obrigacao($tenant, $caso, 'Original avulsa', '2026-01-10');

        $vigente = $this->acordo($tenant, $caso, StatusAcordo::Ativo);
        $this->obrigacao($tenant, $caso, 'Original substituída', '2026-02-10')->setAcordoSubstituto($vigente);
        $this->obrigacao($tenant, $caso, 'Parcela do vigente', '2026-03-10')->setAcordoOrigem($vigente);

        $cumprido = $this->acordo($tenant, $caso, StatusAcordo::Cumprido);
        $this->obrigacao($tenant, $caso, 'Parcela do cumprido', '2026-04-10')->setAcordoOrigem($cumprido);

        $rompido = $this->acordo($tenant, $caso, StatusAcordo::Rompido);
        $this->obrigacao($tenant, $caso, 'Parcela do rompido', '2026-05-10')->setAcordoOrigem($rompido);

        $this->em->flush();

        $exigiveisIds = array_map(
            static fn (Obrigacao $o): int => (int) $o->getId(),
            $this->repo->doCasoExigiveis($caso),
        );
        $substituiveis = $this->repo->doCasoSubstituiveis($caso);

        foreach ($substituiveis as $obrigacao) {
            self::assertContains(
                (int) $obrigacao->getId(),
                $exigiveisIds,
                'Toda substituível tem de ser exigível — os critérios não podem divergir.',
            );
            self::assertNull(
                $obrigacao->getAcordoOrigem(),
                'Nenhuma substituível pode ser parcela de acordo.',
            );
        }

        // `Cumprido` também é vigente (StatusAcordo::ehVigente): sua parcela é exigível, mas não substituível.
        self::assertSame(['Original avulsa'], $this->descricoes($substituiveis));
    }

    #[Test]
    #[TestDox('Detecta acordo sobre acordo: parcelas de A que um acordo B vigente renegociou')]
    public function detectaParcelasRenegociadasPorAcordoVigente(): void
    {
        $tenant = $this->tenant();
        $caso = $this->caso($tenant);

        // Estado LEGADO de acordo sobre acordo: B (vigente) substituiu uma parcela de A (vigente).
        $acordoA = $this->acordo($tenant, $caso, StatusAcordo::Ativo);
        $parcelaRenegociada = $this->obrigacao($tenant, $caso, 'Parcela 1/2 de A', '2026-03-10');
        $parcelaRenegociada->setAcordoOrigem($acordoA);
        $parcelaIntacta = $this->obrigacao($tenant, $caso, 'Parcela 2/2 de A', '2026-04-10');
        $parcelaIntacta->setAcordoOrigem($acordoA);

        $acordoB = $this->acordo($tenant, $caso, StatusAcordo::Ativo);
        $parcelaRenegociada->setAcordoSubstituto($acordoB);

        $this->em->flush();

        // Só a parcela que B renegociou — romper A com ela nesse estado duplicaria a dívida (§2.1).
        self::assertSame(
            ['Parcela 1/2 de A'],
            $this->descricoes($this->repo->parcelasRenegociadasPorAcordoVigente($acordoA)),
        );

        // B não teve parcelas renegociadas por ninguém: pode ser rompido à vontade.
        self::assertSame([], $this->repo->parcelasRenegociadasPorAcordoVigente($acordoB));
    }

    #[Test]
    #[TestDox('Acordo renegociador já desfeito não trava mais o acordo de origem')]
    public function acordoRenegociadorNaoVigenteNaoTravaOrigem(): void
    {
        $tenant = $this->tenant();
        $caso = $this->caso($tenant);

        // B foi cancelado: a parcela de A voltou ao exigível, então romper A não duplica nada.
        $acordoA = $this->acordo($tenant, $caso, StatusAcordo::Ativo);
        $parcela = $this->obrigacao($tenant, $caso, 'Parcela de A', '2026-03-10');
        $parcela->setAcordoOrigem($acordoA);

        $acordoB = $this->acordo($tenant, $caso, StatusAcordo::Cancelado);
        $parcela->setAcordoSubstituto($acordoB);

        $this->em->flush();

        self::assertSame(
            [],
            $this->repo->parcelasRenegociadasPorAcordoVigente($acordoA),
            'Acordo renegociador cancelado/rompido libera o acordo de origem.',
        );
    }

    /**
     * @param Obrigacao[] $obrigacoes
     *
     * @return list<string>
     */
    private function descricoes(array $obrigacoes): array
    {
        return array_values(array_map(
            static fn (Obrigacao $o): string => (string) $o->getDescricao(),
            $obrigacoes,
        ));
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Substituiveis ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function caso(Tenant $tenant): CasoCobranca
    {
        $carteira = $this->carteira($tenant);
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $tenant,
            'objeto' => $objeto,
            'pessoaCobradaAtual' => PessoaFactory::createOne(['tenant' => $tenant]),
            'formaHonorarios' => $carteira->getFormaHonorarios(),
            'percentualHonorarios' => $carteira->getPercentualHonorarios(),
        ])->_real();
    }

    private function carteira(Tenant $tenant): Carteira
    {
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);

        return CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
    }

    private function acordo(Tenant $tenant, CasoCobranca $caso, StatusAcordo $status): Acordo
    {
        return AcordoFactory::createOne(['tenant' => $tenant, 'caso' => $caso, 'status' => $status])->_real();
    }

    private function obrigacao(Tenant $tenant, CasoCobranca $caso, string $descricao, string $vencimento): Obrigacao
    {
        return ObrigacaoFactory::createOne([
            'tenant' => $tenant,
            'caso' => $caso,
            'descricao' => $descricao,
            'valorOriginal' => 100000,
            'encargosReconhecidos' => 0,
            'vencimentoOriginal' => new \DateTimeImmutable($vencimento),
        ])->_real();
    }
}
