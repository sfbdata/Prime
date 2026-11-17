<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Service\LiberacaoDoConector;
use App\Conector\UseCase\ObterConectorDoEscritorioUseCase;
use App\Master\Entity\RecursoEstado;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * O estado do conector do escritório da sessão, para o cartão do administrador (spec mcp-remoto-oauth 14.a; PR 8):
 * só a linha do próprio escritório, sem as conexões da equipe (decisão 5).
 */
#[CoversClass(ObterConectorDoEscritorioUseCase::class)]
final class ObterConectorDoEscritorioUseCaseTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): ObterConectorDoEscritorioUseCase
    {
        return static::getContainer()->get(ObterConectorDoEscritorioUseCase::class);
    }

    #[TestDox('sem linha: tudo falso; a linha liberada de OUTRO escritório não vaza')]
    public function testSemLinha(): void
    {
        $tenant = $this->escritorio();
        $outro = $this->escritorio();
        $this->liberar($outro, $this->pessoa());

        $saida = $this->sut()->executar($tenant);

        self::assertSame([(int) $tenant->getId(), $tenant->getName(), false, false, null, false, 0], [$saida->tenantId, $saida->nome, $saida->liberado, $saida->ligado, $saida->aceiteEm, $saida->aceiteVigente, $saida->conexoesAtivas]);
    }

    #[TestDox('liberado, ligado e com aceite: os três aparecem; a contagem das conexões fica de fora (zero)')]
    public function testLigado(): void
    {
        $tenant = $this->escritorio();
        $linha = $this->liberar($tenant, $this->pessoa());

        $saida = $this->sut()->executar($tenant);

        self::assertTrue($saida->liberado);
        self::assertTrue($saida->ligado);
        self::assertTrue($saida->aceiteVigente);
        self::assertEquals($linha->getAceiteEm(), $saida->aceiteEm);
        self::assertSame(0, $saida->conexoesAtivas);
    }

    #[TestDox('chave geral desligada pelo Master: o cartão sai como não liberado (aguardando liberação), mesmo com a linha ligada')]
    public function testChaveGeralDesligada(): void
    {
        $tenant = $this->escritorio();
        $this->liberar($tenant, $this->pessoa());
        $em = static::getContainer()->get('doctrine')->getManager();
        $estado = $em->getRepository(RecursoEstado::class)->findOneBy(['chave' => LiberacaoDoConector::CHAVE_GERAL])
            ?? new RecursoEstado(LiberacaoDoConector::CHAVE_GERAL);
        $estado->desativar('Pausa', null, null, new \DateTimeImmutable());
        $em->persist($estado);
        $em->flush();

        $saida = $this->sut()->executar($tenant);

        self::assertFalse($saida->liberado);
        self::assertTrue($saida->ligado);
    }
}
