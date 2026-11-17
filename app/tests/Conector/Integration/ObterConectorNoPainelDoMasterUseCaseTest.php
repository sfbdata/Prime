<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\EscritorioNoConectorOutput;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\UseCase\ObterConectorNoPainelDoMasterUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A tabela do conector no painel do Master (spec mcp-remoto-oauth 14.a; decisão 6 do PR 8): todo escritório ativo, com
 * a liberação, o "ligado", o aceite (vigente ou de versão anterior) e só a CONTAGEM das conexões ativas.
 */
#[CoversClass(ObterConectorNoPainelDoMasterUseCase::class)]
final class ObterConectorNoPainelDoMasterUseCaseTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /** @return list<EscritorioNoConectorOutput> */
    private function painel(): array
    {
        return static::getContainer()->get(ObterConectorNoPainelDoMasterUseCase::class)->executar($this->pessoa(['ROLE_SUPER_ADMIN']));
    }

    /** @param list<EscritorioNoConectorOutput> $linhas */
    private function linhaDe(array $linhas, Tenant $tenant): ?EscritorioNoConectorOutput
    {
        foreach ($linhas as $linha) {
            if ($linha->tenantId === $tenant->getId()) {
                return $linha;
            }
        }

        return null;
    }

    #[TestDox('quem não é Master é recusado')]
    public function testNaoMasterRecusado(): void
    {
        $this->expectException(AccessDeniedException::class);

        static::getContainer()->get(ObterConectorNoPainelDoMasterUseCase::class)->executar($this->pessoa(['ROLE_USER']));
    }

    #[TestDox('escritório sem linha: tudo falso e zero conexões; com linha e aceite vigente: os níveis, a data e a contagem só das ativas')]
    public function testLinhas(): void
    {
        $semLinha = $this->escritorio();
        $ligado = $this->escritorio();
        $admin = $this->pessoa();
        $this->liberar($ligado, $admin);
        $cliente = $this->appDeIa();
        $this->em()->persist(new ConsentimentoDoConector($this->pessoa(), $ligado, $cliente, ['mcp:leitura'], $this->agora()));
        $this->em()->persist(new ConsentimentoDoConector($this->pessoa(), $ligado, $cliente, ['mcp:leitura'], $this->agora()));
        $revogado = new ConsentimentoDoConector($this->pessoa(), $ligado, $cliente, ['mcp:leitura'], $this->agora());
        $revogado->revogar($this->agora());
        $this->em()->persist($revogado);
        $this->em()->flush();

        $linhas = $this->painel();

        $vazia = $this->linhaDe($linhas, $semLinha);
        self::assertNotNull($vazia);
        self::assertSame([$semLinha->getName(), false, false, null, false, 0], [$vazia->nome, $vazia->liberado, $vazia->ligado, $vazia->aceiteEm, $vazia->aceiteVigente, $vazia->conexoesAtivas]);
        $cheia = $this->linhaDe($linhas, $ligado);
        self::assertNotNull($cheia);
        self::assertTrue($cheia->liberado);
        self::assertTrue($cheia->ligado);
        self::assertTrue($cheia->aceiteVigente);
        self::assertSame($this->agora()->format('Y-m-d'), $cheia->aceiteEm?->format('Y-m-d'));
        self::assertSame(2, $cheia->conexoesAtivas, 'a revogada não conta');
    }

    #[TestDox('aceite de versão anterior sai com a data e aceiteVigente falso; escritório suspenso ou excluído não entra')]
    public function testAceiteAntigoEInativos(): void
    {
        $antigo = $this->escritorio();
        $linha = $this->liberar($antigo, $this->pessoa());
        $this->em()->getConnection()->executeStatement('UPDATE conector_ia_escritorio SET aceite_versao = 0 WHERE id = ?', [(int) $linha->getId()]);
        $suspenso = $this->escritorio();
        $suspenso->setIsActive(false);
        $excluido = $this->escritorio();
        $excluido->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em()->persist(new EscritorioDoConector($suspenso));
        $this->em()->flush();

        $linhas = $this->painel();

        $doAntigo = $this->linhaDe($linhas, $antigo);
        self::assertNotNull($doAntigo);
        self::assertNotNull($doAntigo->aceiteEm);
        self::assertFalse($doAntigo->aceiteVigente);
        self::assertNull($this->linhaDe($linhas, $suspenso));
        self::assertNull($this->linhaDe($linhas, $excluido));
    }
}
