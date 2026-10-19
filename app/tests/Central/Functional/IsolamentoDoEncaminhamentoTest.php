<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Entity\Tarefa\Tarefa;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A guarda de escritório EXPLÍCITA das consultas novas do "Encaminhar via Chat I.A", com o `TenantFilter` DESLIGADO
 * (mesmo molde de `IsolamentoDosRepositoriosDaCentralTest`): no teste HTTP o filtro pegaria antes e esconderia a falta
 * dela. O item de prova é do escritório B pendurado numa pasta do A: só o `tenant` do WHERE o separa.
 */
#[CoversClass(PastaObservacaoDetalhesRepository::class)]
#[CoversClass(PastaObservacaoFinanceiraRepository::class)]
#[CoversClass(TarefaRepository::class)]
#[CoversClass(GuardaDoEncaminhamento::class)]
final class IsolamentoDoEncaminhamentoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    #[TestDox('Sem o TenantFilter, observação de Detalhes, do Financeiro e meta de outro escritório não são alcançadas pela pasta do A')]
    public function testItensSemFiltro(): void
    {
        static::createClient();
        [$adminA, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $pastaA = $this->criarPasta($escritorioA);

        $detalhes = (new PastaObservacaoDetalhes())->setTenant($escritorioB)->setPasta($pastaA)->setAutor($adminA)->setConteudo('do B');
        $financeira = (new PastaObservacaoFinanceira())->setTenant($escritorioB)->setPasta($pastaA)->setAutor($adminA)->setConteudo('do B');
        $this->em()->persist($detalhes);
        $this->em()->persist($financeira);
        $meta = $this->criarMeta($escritorioB, $pastaA, $adminA, 'Meta do B');
        $this->em()->flush();
        $this->desligarFiltro();

        /** @var PastaObservacaoDetalhesRepository $repoDetalhes */
        $repoDetalhes = $this->em()->getRepository(PastaObservacaoDetalhes::class);
        /** @var PastaObservacaoFinanceiraRepository $repoFinanceiro */
        $repoFinanceiro = $this->em()->getRepository(PastaObservacaoFinanceira::class);
        /** @var TarefaRepository $repoMetas */
        $repoMetas = $this->em()->getRepository(Tarefa::class);

        self::assertNull($repoDetalhes->findByIdAndPastaAndTenant((int) $detalhes->getId(), $pastaA, $escritorioA));
        self::assertNull($repoFinanceiro->findByIdAndPastaAndTenant((int) $financeira->getId(), $pastaA, $escritorioA));
        self::assertNull($repoMetas->findByIdAndPastaAndTenant((int) $meta->getId(), $pastaA, $escritorioA));
        self::assertSame($detalhes, $repoDetalhes->findByIdAndPastaAndTenant((int) $detalhes->getId(), $pastaA, $escritorioB), 'controle');
        self::assertSame($financeira, $repoFinanceiro->findByIdAndPastaAndTenant((int) $financeira->getId(), $pastaA, $escritorioB), 'controle');
        self::assertSame($meta, $repoMetas->findByIdAndPastaAndTenant((int) $meta->getId(), $pastaA, $escritorioB), 'controle');
    }

    #[TestDox('Sem o TenantFilter, nem o super admin (que vê toda pasta) alcança a pasta de outro escritório pelo id')]
    public function testPastaDeOutroEscritorioSemFiltro(): void
    {
        static::createClient();
        [$admin, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $pastaB = $this->criarPasta($escritorioB);
        $this->desligarFiltro();
        $guarda = static::getContainer()->get(GuardaDoEncaminhamento::class);

        self::assertSame($pastaB, $guarda->pastaQueEuVejo((int) $pastaB->getId(), $admin, $escritorioB), 'controle');
        $this->expectException(ItemEncaminhadoNaoEncontradoException::class);
        $guarda->pastaQueEuVejo((int) $pastaB->getId(), $admin, $escritorioA);
    }

    private function desligarFiltro(): void
    {
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }
}
