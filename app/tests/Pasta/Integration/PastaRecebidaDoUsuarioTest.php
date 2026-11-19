<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Exception\PastaRecebidaNaoEncontradaException;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A porta da pasta recebida de outra conta (spec acesso-restrito-e-compartilhar §4.5.1): as cinco condições do
 * `localizar()`, com o filtro do escritório da sessão (B) LIGADO como numa requisição, e o mesmo critério em lote
 * (`quaisAbrem()`). Teste de integração (banco de verdade, DAMA desfaz): o serviço lê com o filtro desligado e as
 * regras de A só existem no banco; mock do EntityManager provaria nada.
 */
#[CoversClass(PastaRecebidaDoUsuario::class)]
final class PastaRecebidaDoUsuarioTest extends KernelTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $contaA;
    private Tenant $contaB;
    private User $donoA;
    private User $olga;
    private User $bia;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        [$this->donoA, $this->contaA] = $this->criarEscritorio();
        [, $this->contaB] = $this->criarEscritorio();
        $this->olga = $this->criarColaborador($this->contaA, 'Olga Origem', self::PERMISSOES);
        $this->bia = $this->criarColaborador($this->contaB, 'Bia Parceira', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->contaA, 'CLIENTE DA CONTA A');
    }

    #[TestDox('convite aceito e vigente: devolve a pasta de A, quem republicou e as abas ocultas para quem recebeu')]
    public function testLocaliza(): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Financeiro, $this->donoA, new \DateTimeImmutable()));
        $this->em()->flush();
        $id = $this->convite($this->olga);
        $this->ligarFiltroEmB();

        $recebida = $this->localizador()->localizar($id, $this->bia);

        self::assertSame([$id, $this->pasta->getId(), $this->contaA->getId(), $this->olga->getId(), false], [$recebida->compartilhamentoId(), $recebida->pastaId(), $recebida->tenant->getId(), $recebida->origem->getId(), $recebida->soLeitura]);
        self::assertContains(AbaDaPasta::Financeiro->value, $recebida->abasOcultas);
        self::assertFalse($recebida->abaVisivel(AbaDaPasta::Financeiro));
        self::assertTrue($recebida->abaVisivel(AbaDaPasta::Documentos));
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('escritório A suspenso: localiza só para leitura')]
    public function testASuspensoSoLeitura(): void
    {
        $id = $this->convite();
        $this->contaA->setIsActive(false);
        $this->em()->flush();
        $this->ligarFiltroEmB();

        self::assertTrue($this->localizador()->localizar($id, $this->bia)->soLeitura);
    }

    /** @return iterable<string, array{0: string}> uma quebra de cada uma das cinco condições */
    public static function condicoes(): iterable
    {
        yield '1. convite: pendente' => ['pendente'];
        yield '1. convite: de outra pessoa' => ['outra pessoa'];
        yield '1. convite: interno' => ['interno'];
        yield '1. convite: vencido' => ['vencido'];
        yield '2. pasta: na lixeira' => ['pasta excluída'];
        yield '2. pasta: com cadeado' => ['cadeado'];
        yield '3. conta A: excluída' => ['A excluído'];
        yield '4. origem: restrita em A' => ['origem restrita'];
        yield '4. origem: sem vínculo ativo em A' => ['origem sem vínculo'];
        yield '4. origem: conta desativada' => ['origem desativada'];
        yield '4. origem: não edita a pasta' => ['origem sem edição'];
        yield '5. destino: com vínculo ativo em A' => ['destino em A'];
    }

    #[DataProvider('condicoes')]
    #[TestDox('não localiza ($caso): exceção sem motivo, e o filtro volta ligado em B')]
    public function testNaoLocaliza(string $caso): void
    {
        $id = $this->quebrar($caso);
        $this->ligarFiltroEmB();

        try {
            $this->localizador()->localizar($id, $this->bia);
            self::fail('devia recusar: ' . $caso);
        } catch (PastaRecebidaNaoEncontradaException $e) {
            self::assertSame('', $e->getMessage());
        }
        $this->assertFiltroLigadoEmB();
    }

    #[DataProvider('condicoes')]
    #[TestDox('quaisAbrem bate com localizar ($caso): o convite não abre, e o filtro volta ligado em B')]
    public function testQuaisAbremBateComLocalizar(string $caso): void
    {
        $id = $this->quebrar($caso);
        // Como na caixa: o convite chega de outra consulta, com as relações por carregar.
        $this->em()->clear();
        $convite = $this->em()->find(PastaCompartilhamento::class, $id);
        self::assertInstanceOf(PastaCompartilhamento::class, $convite);
        $this->ligarFiltroEmB();

        self::assertSame([], $this->localizador()->quaisAbrem([$convite], $this->bia), $caso);
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('quaisAbrem: o mesmo critério em lote, e o filtro volta ligado em B')]
    public function testQuaisAbremEmLote(): void
    {
        $abre = $this->convite();
        $abreDaOlga = $this->convite($this->olga, $this->criarPasta($this->contaA, 'SEGUNDA'));
        $trancada = $this->criarPasta($this->contaA, 'TRANCADA');
        $this->trancar($trancada, $this->donoA);
        $comCadeado = $this->convite(pasta: $trancada);
        $pendente = $this->convite(pasta: $this->criarPasta($this->contaA, 'PENDENTE'), aceito: false);
        $semEdicao = $this->convite($this->criarColaborador($this->contaA, 'Vera Só Vê', ['resources.pasta.view']), $this->criarPasta($this->contaA, 'SEM EDIÇÃO'));
        $convites = array_map(fn (int $id): PastaCompartilhamento => $this->em()->find(PastaCompartilhamento::class, $id), [$abre, $abreDaOlga, $comCadeado, $pendente, $semEdicao]);
        $this->ligarFiltroEmB();

        $abrem = $this->localizador()->quaisAbrem(array_values(array_filter($convites)), $this->bia);

        self::assertSame([$abre => true, $abreDaOlga => true], $abrem);
        $this->assertFiltroLigadoEmB();
    }

    private function quebrar(string $caso): int
    {
        $agora = new \DateTimeImmutable();
        switch ($caso) {
            case 'pendente':
                return $this->convite(aceito: false);
            case 'outra pessoa':
                return $this->convite(destino: $this->criarColaborador($this->contaB, 'Caio de B', self::PERMISSOES));
            case 'interno':
                $c = PastaCompartilhamento::interno($this->pasta, $this->donoA, $this->olga, new PermissoesDoCompartilhamento(), null, null, $agora);
                $this->em()->persist($c);
                $this->em()->flush();

                return (int) $c->getId();
            case 'vencido':
                $id = $this->convite();
                $c = $this->em()->find(PastaCompartilhamento::class, $id);
                (new \ReflectionProperty(PastaCompartilhamento::class, 'validoAte'))->setValue($c, new \DateTimeImmutable('-1 day'));
                $this->em()->flush();

                return $id;
            case 'pasta excluída':
                $this->pasta->marcarExcluida($this->donoA, $agora);
                $this->em()->flush();

                return $this->convite();
            case 'cadeado':
                $this->trancar($this->pasta, $this->donoA);

                return $this->convite();
            case 'A excluído':
                $this->contaA->setExcluidoEm($agora);
                $this->em()->flush();

                return $this->convite();
            case 'origem restrita':
                $this->vinculo($this->olga, $this->contaA)->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem sem vínculo':
                $this->em()->remove($this->vinculo($this->olga, $this->contaA));
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem desativada':
                $this->olga->setIsActive(false);
                $this->em()->flush();

                return $this->convite($this->olga);
            case 'origem sem edição':
                return $this->convite($this->criarColaborador($this->contaA, 'Vera Só Vê', ['resources.pasta.view']));
            case 'destino em A':
                $this->vincular($this->bia, $this->contaA, false, self::PERMISSOES);

                return $this->convite();
        }

        throw new \LogicException('caso desconhecido: ' . $caso);
    }

    private function convite(?User $origem = null, ?Pasta $pasta = null, ?User $destino = null, bool $aceito = true): int
    {
        $destino ??= $this->bia;
        $c = PastaCompartilhamento::externo($pasta ?? $this->pasta, $origem ?? $this->donoA, $destino, new PermissoesDoCompartilhamento(), null, null, new \DateTimeImmutable('-2 days'));
        if ($aceito) {
            $c->aceitar($destino, new \DateTimeImmutable('-1 day'));
        }
        $this->em()->persist($c);
        $this->em()->flush();

        return (int) $c->getId();
    }

    private function vinculo(User $pessoa, Tenant $tenant): UserTenant
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $pessoa, 'tenant' => $tenant]);
        self::assertInstanceOf(UserTenant::class, $vinculo);

        return $vinculo;
    }

    private function localizador(): PastaRecebidaDoUsuario
    {
        return static::getContainer()->get(PastaRecebidaDoUsuario::class);
    }

    private function ligarFiltroEmB(): void
    {
        $this->em()->getFilters()->enable('tenant')->setParameter('tenant', (int) $this->contaB->getId(), Types::INTEGER);
    }

    private function assertFiltroLigadoEmB(): void
    {
        $filtros = $this->em()->getFilters();
        self::assertTrue($filtros->isEnabled('tenant'));
        self::assertSame((int) $this->contaB->getId(), (int) trim((string) $filtros->getFilter('tenant')->getParameter('tenant'), "'"));
    }
}
