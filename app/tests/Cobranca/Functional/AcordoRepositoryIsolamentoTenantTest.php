<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Enum\StatusAcordo;
use App\Cobranca\Repository\AcordoRepository;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Isolamento por tenant da busca `findOnePorNumeroExternoNaCarteira` — a porta de entrada do acordo na
 * importação de Receitas (etapa 3).
 *
 * 🔑 **KernelTestCase, e não WebTestCase, de propósito.** Existe um `TenantFilter` SQL GLOBAL do
 * Doctrine, ligado POR REQUEST. Num teste funcional que passa pelo HTTP ele já barra o dado alheio
 * sozinho — então o teste passaria mesmo com a query sem `andWhere('a.tenant = :tenant')`, e não
 * provaria nada do filtro explícito.
 *
 * O importador roda em **CLI**, onde esse filtro fica DESLIGADO. É exatamente o cenário deste arquivo:
 * sem request, sem sessão, com dado CRUZADO no banco. Se a query perder o filtro de tenant, aqui fica
 * vermelho — e só aqui.
 */
#[CoversClass(AcordoRepository::class)]
final class AcordoRepositoryIsolamentoTenantTest extends KernelTestCase
{
    // ⚠️ SEM `ResetDatabase`. Neste projeto o `saas_test` vem de DUMP, e o trait o derruba e recria —
    // o que matou 1.217 testes numa rodada inteira da suíte. O isolamento aqui é o do DAMA (rollback
    // transacional por teste), o mesmo do resto da suíte.
    use Factories;

    #[TestDox('🔑 Acordo de OUTRO escritório com o mesmo número externo nunca é devolvido (CLI, sem request)')]
    public function testNaoDevolveAcordoDeOutroTenant(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $repo = static::getContainer()->get(AcordoRepository::class);

        // Dado CRUZADO: o MESMO número externo, na mesma posição do grafo, em dois escritórios.
        // Cenário "outro tenant, outro número" não discriminaria nada.
        [$tenantA, $carteiraA] = $this->escritorioComAcordo($em, 348);
        [, $carteiraB] = $this->escritorioComAcordo($em, 348);

        $achado = $repo->findOnePorNumeroExternoNaCarteira(348, $carteiraA, $tenantA);
        self::assertNotNull($achado, 'pré-condição: o acordo do próprio escritório é encontrado');
        self::assertSame($tenantA->getId(), $achado->getTenant()?->getId());

        // A busca com a carteira do OUTRO escritório, passando o tenant deste, não pode achar nada.
        self::assertNull(
            $repo->findOnePorNumeroExternoNaCarteira(348, $carteiraB, $tenantA),
            'carteira de outro escritório com o mesmo número não pode vazar para este tenant',
        );
    }

    #[TestDox('E o filtro global do Doctrine está mesmo DESLIGADO aqui — senão o teste acima não prova nada')]
    public function testOFiltroGlobalNaoEstaLigadoNesteContexto(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        // A guarda da PREMISSA. Se um dia o filtro passar a ligar também em CLI, o teste de cima vira
        // tautologia (passaria com ou sem o `andWhere`) sem ninguém perceber. Este assert derruba a
        // suíte no dia em que isso mudar, para a prova ser reescrita em vez de silenciosamente perdida.
        // ⚠️ O nome é `tenant` — é como o filtro está registrado em `config/packages/doctrine.yaml:21`.
        // A primeira versão deste assert procurava por `tenant_filter`, que não existe: ele passaria
        // com o filtro ligado ou desligado. Assert que não pode falhar é o defeito mais comum desta
        // frente, e ele reapareceu aqui, dentro do teste escrito para vigiar premissas.
        $filtros = $em->getFilters();

        // O estado é fotografado ANTES de qualquer manipulação — é ele que responde à pergunta do
        // teste. Ligar e desligar depois, e então perguntar, seria uma tautologia.
        $ligadosNoInicio = $filtros->getEnabledFilters();

        // Pré-condição do assert: o filtro EXISTE com esse nome. Sem esta verificação, um nome errado
        // faz o `assertArrayNotHasKey` passar para sempre — foi o que aconteceu na 1ª versão daqui,
        // que procurava por `tenant_filter`.
        $filtros->enable('tenant');
        self::assertArrayHasKey('tenant', $filtros->getEnabledFilters(), 'pré-condição: o filtro se chama "tenant"');
        $filtros->disable('tenant');

        self::assertArrayNotHasKey(
            'tenant',
            $ligadosNoInicio,
            'o TenantFilter não pode estar ligado neste contexto — é a premissa do teste de isolamento',
        );
    }

    /** @return array{0: Tenant, 1: Carteira} */
    private function escritorioComAcordo(EntityManagerInterface $em, int $numeroExterno): array
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();
        $objeto = ObjetoCobrancaFactory::createOne(['tenant' => $tenant, 'carteira' => $carteira]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant]);
        $caso = CasoCobrancaFactory::createOne([
            'tenant' => $tenant, 'objeto' => $objeto, 'pessoaCobradaAtual' => $pessoa,
        ])->_real();

        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setStatus(StatusAcordo::Ativo);
        $acordo->setDataAcordo(new \DateTimeImmutable('2026-01-10'));
        $acordo->setNumeroExterno($numeroExterno);
        $acordo->setNumeroParcelasTotal(3);
        $em->persist($acordo);
        $em->flush();

        self::assertInstanceOf(CasoCobranca::class, $caso);

        return [$tenant, $carteira];
    }
}
