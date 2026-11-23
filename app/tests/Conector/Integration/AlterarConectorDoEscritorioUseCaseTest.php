<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\VersaoDoAceiteDoConector;
use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Tests\Conector\Support\LiberacaoDoConectorDeTeste;
use App\Conector\Service\ValidadorDoTokenDoConector;
use App\Conector\UseCase\AlterarConectorDoEscritorioUseCase;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RecursosDoSistema;
use App\Tests\Conector\Support\CadeiaDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * O administrador liga (com o aceite) ou desliga o conector no próprio escritório (spec mcp-remoto-oauth 14.a, nível
 * 3; PR 8). Ligar exige só a caixa marcada, sem liberação do Master (decisão do Samuel, 19/11); o aceite grava a versão vigente e o autor
 * e não é regravado ao religar. Desligar só suspende (decisão 4): a liberação recusa e o mesmo token recebe 403 na
 * chamada seguinte (sem `invalid_token`, então o app não reconecta); religar volta a aceitar sem reconectar.
 */
#[CoversClass(AlterarConectorDoEscritorioUseCase::class)]
final class AlterarConectorDoEscritorioUseCaseTest extends KernelTestCase
{
    use CadeiaDoMcpTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): AlterarConectorDoEscritorioUseCase
    {
        return static::getContainer()->get(AlterarConectorDoEscritorioUseCase::class);
    }

    private function liberacao(): LiberacaoDoConector
    {
        return LiberacaoDoConectorDeTeste::montar(
            static::getContainer()->get(RecursosDoSistema::class),
            static::getContainer()->get(EscritorioDoConectorRepository::class),
        );
    }

    private function linhaDe(Tenant $tenant): ?EscritorioDoConector
    {
        return $this->em()->getRepository(EscritorioDoConector::class)->findOneBy(['tenant' => $tenant->getId()]);
    }

    private function liberadoSemLigar(Tenant $tenant): EscritorioDoConector
    {
        $linha = new EscritorioDoConector($tenant);
        $this->em()->persist($linha);
        $this->em()->flush();

        return $linha;
    }

    private function exigirRecusa(Tenant $tenant, string $porque): void
    {
        try {
            $this->liberacao()->exigir($tenant);
            self::fail('passou: ' . $porque);
        } catch (AcessoDoConectorRecusado $e) {
            self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $e->motivo, $porque);
        }
    }

    private function ligarRecusado(bool $aceite, Tenant $tenant, string $esperado): void
    {
        try {
            $this->sut()->ligar($aceite, $this->pessoa(), $tenant);
            self::fail('ligou sem ' . $esperado);
        } catch (\DomainException $e) {
            self::assertSame($esperado, $e->getMessage());
        }
    }

    #[TestDox('escritório sem nenhuma linha liga só com a caixa marcada: a linha nasce, com o aceite, sem ação do Master')]
    public function testSemLinhaLigaSemMaster(): void
    {
        $semLinha = $this->escritorio();
        self::assertNull($this->linhaDe($semLinha));

        $this->sut()->ligar(true, $this->pessoa(), $semLinha);

        $linha = $this->linhaDe($semLinha);
        self::assertTrue($linha?->isLigado());
        self::assertNotNull($linha->getAceiteEm());
        $this->liberacao()->exigir($semLinha);
    }

    #[TestDox('sem linha e sem a caixa marcada, ligar é recusado e nenhuma linha nasce')]
    public function testSemLinhaSemAceiteNaoCria(): void
    {
        $semLinha = $this->escritorio();

        $this->ligarRecusado(false, $semLinha, AlterarConectorDoEscritorioUseCase::SEM_ACEITE);

        self::assertNull($this->linhaDe($semLinha));
    }

    #[TestDox('sem a caixa "Li e aceito" marcada, ligar é recusado e nada muda')]
    public function testSemAceiteRecusa(): void
    {
        $tenant = $this->escritorio();
        $linha = $this->liberadoSemLigar($tenant);

        $this->ligarRecusado(false, $tenant, AlterarConectorDoEscritorioUseCase::SEM_ACEITE);

        self::assertFalse($linha->isLigado());
        self::assertNull($linha->getAceiteEm());
        $this->exigirRecusa($tenant, 'sem aceite');
    }

    #[TestDox('ligar com o aceite grava a versão vigente, o autor e o momento; os três níveis passam')]
    public function testLigarGravaOAceite(): void
    {
        self::mockTime('2026-11-16 10:00:00');
        $tenant = $this->escritorio();
        $this->liberadoSemLigar($tenant);
        $admin = $this->pessoa();

        $this->sut()->ligar(true, $admin, $tenant);

        $this->em()->clear();
        $gravada = $this->linhaDe($tenant);
        self::assertNotNull($gravada);
        self::assertTrue($gravada->isLigado());
        self::assertSame(VersaoDoAceiteDoConector::atual()->value, $gravada->getAceiteVersao());
        self::assertSame($admin->getId(), $gravada->getAceitoPor()?->getId());
        self::assertSame('2026-11-16 10:00:00', $gravada->getAceiteEm()?->format('Y-m-d H:i:s'));
        $this->liberacao()->exigir($tenant);
    }

    #[TestDox('religar não regrava o aceite vigente: continuam o primeiro autor e o primeiro momento')]
    public function testReligarNaoRegrava(): void
    {
        self::mockTime('2026-11-16 10:00:00');
        $tenant = $this->escritorio();
        $linha = $this->liberadoSemLigar($tenant);
        $primeiro = $this->pessoa();
        $this->sut()->ligar(true, $primeiro, $tenant);
        $this->sut()->desligar($tenant);

        self::mockTime('2026-11-17 15:30:00');
        $this->sut()->ligar(true, $this->pessoa(), $tenant);

        self::assertTrue($linha->isLigado());
        self::assertSame($primeiro->getId(), $linha->getAceitoPor()?->getId());
        self::assertSame('2026-11-16 10:00:00', $linha->getAceiteEm()?->format('Y-m-d H:i:s'));
    }

    #[TestDox('desligar faz a liberação recusar, mantém o aceite e não mexe no outro escritório')]
    public function testDesligarFazExigirRecusar(): void
    {
        $tenant = $this->escritorio();
        $outro = $this->escritorio();
        $admin = $this->pessoa();
        $linha = $this->liberar($tenant, $admin);
        $this->liberar($outro, $admin);

        $this->sut()->desligar($tenant);

        self::assertFalse($linha->isLigado());
        self::assertNotNull($linha->getAceiteEm(), 'o aceite é histórico');
        $this->exigirRecusa($tenant, 'desligado');
        $this->liberacao()->exigir($outro);
    }

    #[TestDox('desligar sem linha não cria linha nenhuma')]
    public function testDesligarSemLinha(): void
    {
        $tenant = $this->escritorio();

        $this->sut()->desligar($tenant);

        self::assertNull($this->linhaDe($tenant));
    }

    #[TestDox('desligar só suspende: o mesmo token é recusado na chamada seguinte (403, sem invalid_token), a conexão não é revogada e religar volta a aceitar')]
    public function testDesligarSuspendeOToken(): void
    {
        $conexao = $this->conexaoAutorizada();
        $bearer = $this->tokenDeAcesso($conexao['concessao']);
        self::assertTrue($this->validador()->validate($bearer)->isAllowed());

        $this->sut()->desligar($conexao['escritorio']);
        $this->validador()->reset();

        $recusa = $this->validador()->validate($bearer);
        self::assertSame(403, $recusa->getStatusCode());
        self::assertSame(ValidadorDoTokenDoConector::ERRO_CONECTOR_DESLIGADO, $recusa->getError());
        self::assertSame(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, $this->validador()->motivoDaRecusa());
        self::assertNull($conexao['consentimento']->getRevogadoEm(), 'desligar não revoga a conexão');
        self::assertNull($conexao['concessao']->getRevogadaEm(), 'nem a concessão');

        $this->sut()->ligar(true, $conexao['pessoa'], $conexao['escritorio']);
        $this->validador()->reset();

        self::assertTrue($this->validador()->validate($bearer)->isAllowed(), 'religar volta sem reconectar');
    }
}
