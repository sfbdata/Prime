<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\AbonoPelaAnaliseDaJustificativa;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Abonar selecionadas" da aba Equipe com o abono de verdade, o da ficha (`AnalisarJustificativaUseCase::abonarPorId`),
 * sem dublê: grava no banco, e a recusa da ficha volta como a frase da pessoa e do dia sem desfazer as outras
 * (spec `docs/specs/ponto-desenho-0811.md`, E5). O resto do lote se prova em AbonoEmLoteDaEquipeTest.
 */
#[CoversClass(AbonoPelaAnaliseDaJustificativa::class)]
#[CoversClass(AbonarJustificativasDaEquipeUseCase::class)]
#[Group('ponto')]
final class AbonoEmLoteLigadoAFichaTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $gestora;
    private User $ana;
    private User $bruno;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfFalso();
        $this->hoje = new \DateTimeImmutable('today');
        $this->escritorio = $this->escritorio('Escritório do lote ligado');
        $this->gestora = $this->pessoa('Gestora');
        $this->vincular($this->gestora, $this->escritorio, [], true);
        $this->ana = $this->pessoa('Ana Lima');
        $this->vincular($this->ana, $this->escritorio);
        $this->bruno = $this->pessoa('Bruno Reis');
        $this->vincular($this->bruno, $this->escritorio);
    }

    #[TestDox('Abona de verdade: a justificativa em análise fica abonada no banco, por quem abonou')]
    public function testAbonaNoBanco(): void
    {
        $daAna = $this->justificativa($this->ana, -1, 'pendente');

        $this->abonar([$daAna->getId()]);

        self::assertResponseRedirects('/ponto/?aba=equipe');
        self::assertSame(['1 justificativa abonada.'], $this->flashes('success'));
        self::assertSame([], $this->flashes('warning'));
        self::assertSame(['abonado', (string) $this->gestora->getId()], $this->noBanco($daAna));
    }

    #[TestDox('Recusa da ficha (já analisada): vira a frase da pessoa e do dia, não muda no banco, e a outra é abonada')]
    public function testRecusaDaFichaNaoDesfazAsOutras(): void
    {
        $daAna = $this->justificativa($this->ana, -2, 'rejeitado');
        $doBruno = $this->justificativa($this->bruno, -1, 'pendente');

        $this->abonar([$daAna->getId(), $doBruno->getId()]);

        self::assertSame(['1 justificativa abonada.'], $this->flashes('success'));
        $avisos = $this->flashes('warning');
        self::assertCount(1, $avisos);
        self::assertStringStartsWith(sprintf('Ana Lima (%s): ', $this->hoje->modify('-2 days')->format('d/m/Y')), $avisos[0]);
        self::assertSame('rejeitado', $this->noBanco($daAna)[0]);
        self::assertSame(['abonado', (string) $this->gestora->getId()], $this->noBanco($doBruno));
    }

    /** @param list<int|null> $ids */
    private function abonar(array $ids): void
    {
        $this->logarComTenant($this->client, $this->gestora, $this->escritorio);
        $this->client->request('POST', '/ponto/equipe/abonar', ['_token' => 'TOKEN_ponto_equipe_abonar', 'ids' => $ids]);
    }

    /** @return list<string> */
    private function flashes(string $tipo): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($tipo);
    }

    private function justificativa(User $pessoa, int $dias, string $status): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setUser($pessoa);
        $j->setTenant($this->escritorio);
        $j->setData(new \DateTime($this->hoje->modify($dias . ' days')->format('Y-m-d')));
        $j->setStatus($status);
        $j->setTipo('atestado_medico');
        $j->setBatchId(bin2hex(random_bytes(8)));
        $this->em()->persist($j);
        $this->em()->flush();

        return $j;
    }

    /** @return array{0: string, 1: string} situação e quem analisou, lidos do banco */
    private function noBanco(JustificativaPonto $justificativa): array
    {
        $linha = $this->em()->getConnection()->fetchAssociative('SELECT status, analisado_por_id FROM justificativa_ponto WHERE id = ?', [$justificativa->getId()]);
        self::assertIsArray($linha);

        return [(string) $linha['status'], (string) $linha['analisado_por_id']];
    }
}
