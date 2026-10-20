<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\DecisaoSemProcessoDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\RegistroDeDecisaoSemProcesso;
use App\Pasta\UseCase\DefinirPastaAdministrativaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * O aprendizado do relatório (desenho `spMarcar` L4510): marcar "Administrativo sem processo" numa
 * pasta com termos judiciais grava a decisão `administrativo`; desmarcar grava `precisa` na MESMA
 * linha. Sem termos judiciais, nada é gravado.
 */
#[CoversClass(DefinirPastaAdministrativaUseCase::class)]
#[CoversClass(RegistroDeDecisaoSemProcesso::class)]
#[CoversClass(DecisaoSemProcessoDaPasta::class)]
#[Group('pasta')]
final class DecisaoSemProcessoAoMarcarTest extends JusPrimeWebTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $paula;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->tenant = $this->novoEscritorio();
        $this->paula  = $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
        $this->csrfPrevisivel();
        $this->logarComTenant($this->client, $this->paula, $this->tenant);
    }

    private function definir(Pasta $pasta, bool $ligar): void
    {
        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', [
            '_token'         => 'TOKEN_pasta_administrativa_' . $pasta->getId(),
            'administrativa' => $ligar ? '1' : '0',
        ]);
        self::assertResponseRedirects();
    }

    /** @return list<array<string, mixed>> */
    private function decisoes(Pasta $pasta): array
    {
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT decisao, termos, por_id, tenant_id FROM pasta_decisao_sem_processo WHERE pasta_id = ?',
            [$pasta->getId()],
        );
    }

    #[TestDox('Marcar com termos judiciais grava "administrativo"; desmarcar troca para "precisa" (uma linha)')]
    public function testMarcarEDesmarcar(): void
    {
        $pasta = $this->novaPasta($this->tenant, '1180', $this->paula);
        $this->registrar($pasta, $this->paula, 'Audiência de conciliação marcada');

        $this->definir($pasta, true);
        $marcada = $this->decisoes($pasta);
        self::assertCount(1, $marcada);
        self::assertSame('administrativo', $marcada[0]['decisao']);
        self::assertSame(['audiência'], json_decode((string) $marcada[0]['termos'], true));
        self::assertSame($this->paula->getId(), (int) $marcada[0]['por_id']);
        self::assertSame($this->tenant->getId(), (int) $marcada[0]['tenant_id']);

        $this->definir($pasta, false);
        $desmarcada = $this->decisoes($pasta);
        self::assertCount(1, $desmarcada, 'uma decisão por pasta: a nova sobrescreve');
        self::assertSame('precisa', $desmarcada[0]['decisao']);
    }

    #[TestDox('Sem termos judiciais na pasta, marcar não grava decisão')]
    public function testSemTermosNaoGrava(): void
    {
        $pasta = $this->novaPasta($this->tenant, '1181', $this->paula);
        $this->registrar($pasta, $this->paula, 'Parecer de consultoria');

        $this->definir($pasta, true);

        self::assertSame([], $this->decisoes($pasta));
    }
}
