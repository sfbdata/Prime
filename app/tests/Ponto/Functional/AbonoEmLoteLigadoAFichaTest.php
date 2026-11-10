<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Notificacao;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\AbonoPelaAnaliseDaJustificativa;
use App\Ponto\Service\DetalheDaTrilhaDoPonto;
use App\Ponto\UseCase\AbonarJustificativasDaEquipeUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * "Abonar selecionadas" da aba Equipe com o abono de verdade, o da ficha (`AnalisarJustificativaUseCase::abonarPorId`
 * para a própria e `abonarDaEquipe` para as da equipe), sem dublê: grava no banco, a recusa da ficha volta como a frase
 * da pessoa e do dia sem desfazer as outras, a trilha ganha um "Abono em lote" só para as da equipe (desenho L818) e
 * cada pessoa recebe o aviso do sininho (spec `docs/specs/ponto-desenho-0811.md`, E5). O resto do lote se prova em
 * AbonoEmLoteDaEquipeTest.
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
        self::assertSame(['1 justificativa abonada.'], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE), 'E8: no aviso flutuante da /ponto');
        self::assertSame([], $this->flashes('warning'));
        self::assertSame(['abonado', (string) $this->gestora->getId()], $this->noBanco($daAna));
    }

    #[TestDox('Recusa da ficha (já analisada): vira a frase da pessoa e do dia, não muda no banco, e a outra é abonada')]
    public function testRecusaDaFichaNaoDesfazAsOutras(): void
    {
        $daAna = $this->justificativa($this->ana, -2, 'rejeitado');
        $doBruno = $this->justificativa($this->bruno, -1, 'pendente');

        $this->abonar([$daAna->getId(), $doBruno->getId()]);

        self::assertSame(['1 justificativa abonada.'], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE), 'E8: no aviso flutuante da /ponto');
        $avisos = $this->flashes('warning');
        self::assertCount(1, $avisos);
        self::assertStringStartsWith(sprintf('Ana Lima (%s): ', $this->hoje->modify('-2 days')->format('d/m/Y')), $avisos[0]);
        self::assertSame('rejeitado', $this->noBanco($daAna)[0]);
        self::assertSame(['abonado', (string) $this->gestora->getId()], $this->noBanco($doBruno));
    }

    #[TestDox('Desenho L818: 2 da equipe e 1 da própria viram 1 "Justificativa abonada" e 1 "Abono em lote" com "2 justificativas da equipe"')]
    public function testUmEventoParaAsDaEquipe(): void
    {
        $daAna = $this->justificativa($this->ana, -2, 'pendente');
        $daGestora = $this->justificativa($this->gestora, -1, 'pendente');
        $doBruno = $this->justificativa($this->bruno, -1, 'pendente');

        $this->abonar([$daAna->getId(), $daGestora->getId(), $doBruno->getId()]);

        self::assertSame(['3 justificativas abonadas.'], $this->flashes(PontoController::FLASH_AVISO_FLUTUANTE));
        $eventos = $this->eventos();
        self::assertSame(['justificativa_abonada', 'abono_em_lote'], array_column($eventos, 'acao'));
        self::assertSame([(int) $this->gestora->getId(), (int) $this->gestora->getId()], array_column($eventos, 'autor_id'));
        self::assertSame($daGestora->getId(), $eventos[0]['detalhe']['justificativa_id'], 'a própria, sozinha, com o evento dela');
        self::assertSame([
            ['justificativa_id' => $daAna->getId(), 'colaborador_id' => $this->ana->getId(), 'colaborador' => 'Ana Lima', 'data' => $this->hoje->modify('-2 days')->format('Y-m-d')],
            ['justificativa_id' => $doBruno->getId(), 'colaborador_id' => $this->bruno->getId(), 'colaborador' => 'Bruno Reis', 'data' => $this->hoje->modify('-1 day')->format('Y-m-d')],
        ], $eventos[1]['detalhe']['da_equipe'], 'quem foi abonado fica na auditoria');
        self::assertSame('2 justificativas da equipe', DetalheDaTrilhaDoPonto::texto($eventos[1]['acao'], $eventos[1]['detalhe']));
        self::assertSame(['abonado', 'abonado', 'abonado'], [$this->noBanco($daAna)[0], $this->noBanco($daGestora)[0], $this->noBanco($doBruno)[0]]);
        self::assertSame([(int) $this->gestora->getId(), (int) $this->ana->getId(), (int) $this->bruno->getId()], $this->avisadas(), 'o aviso do sininho, como na ficha');
    }

    #[TestDox('Recusa de uma da equipe: o evento conta só as abonadas; depois, nada abonado, nenhum evento')]
    public function testEventoContaSoAsAbonadas(): void
    {
        $daAna = $this->justificativa($this->ana, -2, 'rejeitado');
        $doBruno = $this->justificativa($this->bruno, -1, 'pendente');

        $this->abonar([$daAna->getId(), $doBruno->getId()]);

        $eventos = $this->eventos();
        self::assertSame(['abono_em_lote'], array_column($eventos, 'acao'));
        self::assertSame([$doBruno->getId()], array_column($eventos[0]['detalhe']['da_equipe'], 'justificativa_id'));
        self::assertSame('1 justificativa da equipe', DetalheDaTrilhaDoPonto::texto($eventos[0]['acao'], $eventos[0]['detalhe']));
        self::assertSame([(int) $this->bruno->getId()], $this->avisadas(), 'a recusada não é avisada');

        $this->abonar([$daAna->getId(), $doBruno->getId()]);   // as duas já analisadas: nada abonado

        self::assertResponseRedirects('/ponto/?aba=equipe');
        self::assertCount(1, $this->eventos(), 'nada abonado, nenhum evento');
    }

    #[TestDox('Aba Auditoria: a linha do lote da equipe mostra "Abono em lote" e "2 justificativas da equipe" (desenho L818)')]
    public function testAbaAuditoriaMostraOTextoDoDesenho(): void
    {
        $daAna = $this->justificativa($this->ana, -2, 'pendente');
        $doBruno = $this->justificativa($this->bruno, -1, 'pendente');
        $this->abonar([$daAna->getId(), $doBruno->getId()]);

        $painel = $this->client->request('GET', '/ponto/auditoria');

        self::assertResponseIsSuccessful();
        $linhas = $painel->filter('body > div.pe-audit-rolagem > table.pe-audit-tab > tbody > tr');
        self::assertCount(1, $linhas);
        self::assertSame('Abono em lote', $linhas->filter('tr > td:nth-child(3) > b')->text());
        self::assertSame('2 justificativas da equipe', $linhas->filter('tr > td.pe-audit-detalhe:nth-child(4)')->text());
    }

    /** @return list<array{acao: string, autor_id: ?int, detalhe: array<string, mixed>}> os eventos da trilha do escritório, em ordem */
    private function eventos(): array
    {
        $linhas = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT acao, autor_id, detalhe FROM ponto_auditoria WHERE tenant_id = ? ORDER BY sequencia',
            [$this->escritorio->getId()],
        );

        return array_map(static fn (array $l): array => [
            'acao'     => (string) $l['acao'],
            'autor_id' => $l['autor_id'] === null ? null : (int) $l['autor_id'],
            'detalhe'  => json_decode((string) $l['detalhe'], true),
        ], $linhas);
    }

    /** @return list<int> quem recebeu o aviso "abonada" neste escritório, por id */
    private function avisadas(): array
    {
        return array_map('intval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT usuario_id FROM notificacao WHERE tenant_id = ? AND tipo = ? ORDER BY usuario_id',
            [$this->escritorio->getId(), Notificacao::TIPO_PONTO_JUSTIFICATIVA_APROVADA],
        ));
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
