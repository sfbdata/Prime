<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Enum\TipoDeItemEncaminhado;
use App\Central\Exception\ItemEncaminhadoNaoEncontradoException;
use App\Central\Service\LeituraDasMensagens;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;
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
#[CoversClass(LeituraDasMensagens::class)]
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

    #[TestDox('Sem o TenantFilter, a leitura do despacho não alcança a pasta de outro escritório pela referência, nem com a permissão dizendo sim')]
    public function testLeituraDaReferenciaSemFiltro(): void
    {
        static::createClient();
        [$adminA, $escritorioA] = $this->criarAdmin();
        [, $escritorioB] = $this->criarAdmin();
        $pastaA = $this->criarPasta($escritorioA);
        $pastaB = $this->criarPasta($escritorioB);
        $grupo = Conversa::grupo($escritorioA, $adminA, 'Equipe', new \DateTimeImmutable());
        $this->em()->persist($grupo);
        $despacho = new Mensagem($escritorioA, $grupo, $adminA, 'Despacho: Pasta ' . $pastaB->getNup(), new \DateTimeImmutable());
        $despacho->referenciarDespacho($pastaA, TipoDeItemEncaminhado::Pasta);
        $controle = new Mensagem($escritorioA, $grupo, $adminA, 'Despacho: Pasta ' . $pastaA->getNup(), new \DateTimeImmutable());
        $controle->referenciarDespacho($pastaA, TipoDeItemEncaminhado::Pasta);
        $this->em()->persist($despacho);
        $this->em()->persist($controle);
        $this->em()->flush();
        // A referência torta (pasta do B numa mensagem do A) só entra por fora do domínio, que a recusa.
        $this->em()->getConnection()->executeStatement('UPDATE central_mensagem SET despacho_pasta_id = ? WHERE id = ?', [$pastaB->getId(), $despacho->getId()]);
        $this->em()->clear();
        $this->desligarFiltro();

        $sempreSim = $this->createStub(PermissionChecker::class);
        $sempreSim->method('canAccessResource')->willReturn(true);
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturn(true);
        $leitura = new LeituraDasMensagens(static::getContainer()->get(PastaRepository::class), new AcessoAPasta($sempreSim), $financeiro);
        $leitor = $this->em()->find(User::class, $adminA->getId());
        $mensagens = $this->em()->getRepository(Mensagem::class)->findBy(['id' => [$despacho->getId(), $controle->getId()]], ['id' => 'ASC']);
        [$torta, $boa] = $leitura->paraLeitor($mensagens, $leitor);

        self::assertTrue($torta->ocultoPorPermissao, 'a pasta do B não é achada pela busca com o escritório do A');
        self::assertSame(LeituraDasMensagens::SEM_ACESSO, $torta->conteudo);
        self::assertNull($torta->despacho);
        self::assertFalse($boa->ocultoPorPermissao, 'controle');
        self::assertSame($pastaA->getId(), $boa->despacho?->pastaId);
        self::assertInstanceOf(Pasta::class, $this->em()->find(Pasta::class, $pastaB->getId()), 'sem o filtro a pasta do B existe e seria alcançável pelo id cru');
    }

    private function desligarFiltro(): void
    {
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }
}
