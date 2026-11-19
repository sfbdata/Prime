<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Exception\EscritaNaPastaRecebidaNegadaException;
use App\Pasta\Exception\EscritaNaPastaRecebidaRecusadaException;
use App\Pasta\Service\PastaRecebidaDoUsuario;
use App\Pasta\UseCase\MudarFaseDaPastaRecebidaUseCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A troca de fase de quem recebeu a pasta de outra conta (spec acesso-restrito-e-compartilhar §4.5.1, fatia 9b), com o
 * filtro do escritório da sessão (B) LIGADO como numa requisição: o andamento de A só é achado com o filtro desligado
 * (senão nasceria outro), o contrato não é sincronizado e o filtro de B volta ligado, também na recusa. Banco de
 * verdade (DAMA desfaz): a regra que importa é a consulta com e sem filtro.
 */
#[CoversClass(MudarFaseDaPastaRecebidaUseCase::class)]
final class MudarFaseDaPastaRecebidaUseCaseTest extends KernelTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $contaA;
    private Tenant $contaB;
    private User $donoA;
    private User $bia;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        [$this->donoA, $this->contaA] = $this->criarEscritorio();
        [, $this->contaB] = $this->criarEscritorio();
        $this->bia = $this->criarColaborador($this->contaB, 'Bia Parceira', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->contaA, 'CLIENTE DA CONTA A');
    }

    #[TestDox('avança no andamento gravado de A, sem criar outro e sem sincronizar o contrato; o filtro de B volta ligado')]
    public function testAvancaSemDuplicarNemSincronizarOContrato(): void
    {
        $this->andamentoEm(3, [2]);
        $this->pasta->setSituacaoContrato('REGULAR');
        $this->em()->flush();
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->ligarFiltroEmB();
        $recebida = static::getContainer()->get(PastaRecebidaDoUsuario::class)->localizar($id, $this->bia);

        $texto = $this->useCase()->executar($recebida, $this->bia, 4);

        self::assertSame('Fase da pasta alterada de "Distribuído" para "Protocolo".', $texto);
        $linhas = $this->conexao()->fetchAllAssociative('SELECT atual, marcos FROM pasta_andamento WHERE pasta_id = :p', ['p' => $this->pasta->getId()]);
        self::assertCount(1, $linhas);
        self::assertSame(4, (int) $linhas[0]['atual']);
        self::assertContains(2, json_decode((string) $linhas[0]['marcos'], true, flags: JSON_THROW_ON_ERROR)['pul']);
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = :p AND sistema = 'contrato'", ['p' => $this->pasta->getId()]));
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('cruzar o Contrato é recusado, nada muda e o filtro de B volta ligado')]
    public function testCruzarOContratoRecusa(): void
    {
        $this->andamentoEm(1);
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->ligarFiltroEmB();
        $recebida = static::getContainer()->get(PastaRecebidaDoUsuario::class)->localizar($id, $this->bia);

        try {
            $this->useCase()->executar($recebida, $this->bia, 3);
            self::fail('cruzou o Contrato');
        } catch (EscritaNaPastaRecebidaRecusadaException) {
        }

        self::assertSame(1, (int) $this->conexao()->fetchOne('SELECT atual FROM pasta_andamento WHERE pasta_id = :p', ['p' => $this->pasta->getId()]));
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('sem "Movimentar" ou com A suspenso: negado antes de ler o andamento')]
    public function testSemMovimentarOuASuspensoNega(): void
    {
        $semCaixa = $this->convite(new PermissoesDoCompartilhamento(false, true, true));
        $comCaixa = $this->convite(new PermissoesDoCompartilhamento(true, false, false), $this->criarPasta($this->contaA, 'OUTRA'));
        $this->contaA->setIsActive(false);
        $this->em()->flush();
        $this->ligarFiltroEmB();
        $localizador = static::getContainer()->get(PastaRecebidaDoUsuario::class);

        foreach ([$semCaixa, $comCaixa] as $id) {
            try {
                $this->useCase()->executar($localizador->localizar($id, $this->bia), $this->bia, 1);
                self::fail('escreveu sem poder');
            } catch (EscritaNaPastaRecebidaNegadaException) {
            }
        }
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM pasta_andamento WHERE tenant_id = :a', ['a' => $this->contaA->getId()]));
    }

    /** @param list<int> $puladas */
    private function andamentoEm(int $atual, array $puladas = []): void
    {
        $andamento = new PastaAndamento($this->contaA, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $em = [];
        for ($k = 0; $k <= $atual; ++$k) {
            if (!\in_array($k, $puladas, true)) {
                $em[$k] = (new \DateTimeImmutable('-' . (20 - $k) . ' days'))->format(\DATE_ATOM);
            }
        }
        $andamento->gravar($atual, ['em' => $em, 'pul' => $puladas, 'extra' => []]);
        $this->em()->persist($andamento);
        $this->em()->flush();
    }

    private function convite(PermissoesDoCompartilhamento $permissoes, ?Pasta $pasta = null): int
    {
        $c = PastaCompartilhamento::externo($pasta ?? $this->pasta, $this->donoA, $this->bia, $permissoes, null, null, new \DateTimeImmutable('-2 days'));
        $c->aceitar($this->bia, new \DateTimeImmutable('-1 day'));
        $this->em()->persist($c);
        $this->em()->flush();

        return (int) $c->getId();
    }

    private function useCase(): MudarFaseDaPastaRecebidaUseCase
    {
        return static::getContainer()->get(MudarFaseDaPastaRecebidaUseCase::class);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
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
