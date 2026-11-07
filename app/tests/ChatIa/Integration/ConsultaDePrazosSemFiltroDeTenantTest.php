<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Integration;

use App\ChatIa\DTO\ConsultaDePrazosInput;
use App\ChatIa\Enum\PeriodoDaConsulta;
use App\ChatIa\Service\ListaDasMinhasMetasDaTela;
use App\ChatIa\UseCase\ConsultarPrazosEMetasUseCase;
use App\Entity\Auth\UserTenant;
use App\Tarefa\Enum\AbaMetas;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A guarda de escritório da consulta de prazos e metas com o `TenantFilter` DESLIGADO (fora de uma requisição, como no
 * worker): a consulta da tela Minhas Metas sem etapa não filtra por escritório sozinha (conta com o filtro), então a pessoa que
 * é responsável por metas em DOIS escritórios veria as do outro. O UseCase tira essas metas pelo escritório da sessão.
 */
#[CoversClass(ConsultarPrazosEMetasUseCase::class)]
#[CoversClass(ListaDasMinhasMetasDaTela::class)]
final class ConsultaDePrazosSemFiltroDeTenantTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    #[TestDox('Sem o TenantFilter, a meta do outro escritório da mesma pessoa fica fora da consulta')]
    public function testOutroEscritorioSaiSemOFiltro(): void
    {
        self::bootKernel();
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        $ana = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view', 'modules.tarefas.view'], 'ana');
        [, $tenant2] = $this->criarAdmin();
        $this->em()->persist(new UserTenant($ana, $tenant2));
        $pasta = $this->criarPasta($tenant);
        $pasta2 = $this->criarPasta($tenant2);
        $this->criarMeta($tenant, $pasta, $ana, 'Meta do escritorio da sessao', 'today');
        $this->criarMeta($tenant2, $pasta2, $ana, 'Meta do outro escritorio', 'today');
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'), 'o cenário é o de fora da requisição');

        $daTela = static::getContainer()->get(ListaDasMinhasMetasDaTela::class)->daTela($ana, $tenant, AbaMetas::RESPONSAVEL, 'proximas');
        $saida = static::getContainer()->get(ConsultarPrazosEMetasUseCase::class)
            ->executar($ana, $tenant, new ConsultaDePrazosInput(PeriodoDaConsulta::Hoje));

        self::assertContains('Meta do outro escritorio', array_map(static fn ($m): string => $m->getTitulo(), $daTela), 'a lista sozinha traz a do outro escritório');
        self::assertSame(['Meta do escritorio da sessao'], array_map(static fn ($m): string => $m->titulo, $saida->metas));
        self::assertSame(1, $saida->total);
    }
}
