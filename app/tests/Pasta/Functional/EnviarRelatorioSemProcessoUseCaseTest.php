<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioDoRelatorioSemProcesso;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Repository\EnvioDoRelatorioSemProcessoRepository;
use App\Pasta\Service\MontagemDoRelatorioSemProcesso;
use App\Pasta\UseCase\EnviarRelatorioSemProcessoUseCase;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Enviar à Controladoria" (spec relatorio-processos-controladoria §4; desenho `spEnviar` e
 * `semProcNotificar`). Teste de integração: as consultas (Controladoria, primários, pastas,
 * histórico) são parte da regra e só se provam no banco. O relógio é um MockClock para a regra da
 * primeira segunda-feira.
 *
 * Escritório-base: Paula (primária), Carla (Controladoria, secundária), Rui (responsável, secundário)
 * e Sérgio (primário, quem envia). Pastas: 1180 (do Rui, deveria ter processo), 1181 (do Rui,
 * administrativa coerente), 1182 (da Paula, sem classificação) e 1183 (com processo).
 */
#[CoversClass(EnviarRelatorioSemProcessoUseCase::class)]
#[CoversClass(MontagemDoRelatorioSemProcesso::class)]
#[Group('pasta')]
final class EnviarRelatorioSemProcessoUseCaseTest extends KernelTestCase
{
    use CriaEscritorioSemProcessoTrait;

    /** Primeira segunda-feira de outubro de 2026, 10h05. */
    private const PRIMEIRA_SEGUNDA = '2026-10-05 10:05:00';

    private Tenant $tenant;
    private User $paula;
    private User $carla;
    private User $rui;
    private User $sergio;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tenant  = $this->novoEscritorio();
        $primario      = $this->novoPerfil($this->tenant, true);
        $secundario    = $this->novoPerfil($this->tenant, false);
        $this->paula   = $this->novaPessoa($this->tenant, 'Paula Primária', $primario);
        $this->carla   = $this->novaPessoa($this->tenant, 'Carla Controladoria', $secundario, controladoria: true);
        $this->rui     = $this->novaPessoa($this->tenant, 'Rui Responsável', $secundario);
        $this->sergio  = $this->novaPessoa($this->tenant, 'Sérgio Sócio', $primario);

        $p1180 = $this->novaPasta($this->tenant, '1180', $this->rui);
        $this->registrar($p1180, $this->rui, 'Distribuída a ação, processo 0701134-57.2025.8.07.0007 na vara cível.');
        $p1181 = $this->novaPasta($this->tenant, '1181', $this->rui, administrativa: true);
        $this->registrar($p1181, $this->rui, 'Parecer de consultoria');
        $this->novaPasta($this->tenant, '1182', $this->paula);
        $this->vincularProcessoNovo($this->novaPasta($this->tenant, '1183', $this->rui), '07011345720258070008');
    }

    private function useCase(string $agora = self::PRIMEIRA_SEGUNDA): EnviarRelatorioSemProcessoUseCase
    {
        $c = static::getContainer();

        return new EnviarRelatorioSemProcessoUseCase(
            $c->get(MontagemDoRelatorioSemProcesso::class),
            $c->get(UserTenantRepository::class),
            $c->get(RegraDosPrimarios::class),
            $c->get(EnvioDoRelatorioSemProcessoRepository::class),
            $c->get(NotificacaoService::class),
            $c->get(EntityManagerInterface::class),
            $c->get(UrlGeneratorInterface::class),
            new MockClock(new \DateTimeImmutable($agora)),
        );
    }

    private function envios(): int
    {
        return $this->em()->getRepository(EnvioDoRelatorioSemProcesso::class)->count(['tenant' => $this->tenant->getId()]);
    }

    #[TestDox('Manual: Controladoria e primários recebem o resumo; o responsável, as pastas dele; quem enviou, nada')]
    public function testDestinatariosDoEnvioManual(): void
    {
        $resultado = $this->useCase()->executar($this->tenant, $this->sergio, false);

        self::assertTrue($resultado->enviado);
        self::assertSame(EnviarRelatorioSemProcessoUseCase::MENSAGEM_ENVIADO, $resultado->mensagem);
        $avisos = $this->avisosDoRelatorio($this->tenant);
        self::assertEqualsCanonicalizing([$this->paula->getId(), $this->carla->getId(), $this->rui->getId()], array_keys($avisos));
        self::assertArrayNotHasKey((int) $this->sergio->getId(), $avisos, 'quem enviou não avisa a si mesmo');

        $geral = $avisos[$this->carla->getId()][0];
        self::assertSame('Relatório mensal: 3 pasta(s) sem processo', $geral->getTitulo());
        self::assertSame(
            'Relatório de pastas sem processo (05/10/2026): 3 sem processo vinculado: 1 deveriam ter, 0 precisam de revisão, 1 sem classificação e 1 administrativas coerentes. Primeiro envio.',
            $geral->getMensagem(),
        );
        self::assertSame('/expediente/relatorio-processos', $geral->getUrl());

        // Paula é primária E responsável pela 1182: recebe o resumo e o aviso das pastas dela.
        self::assertCount(2, $avisos[$this->paula->getId()]);
        self::assertCount(1, $avisos[$this->rui->getId()]);
        self::assertSame('Pastas sem processo: 1 sob sua responsabilidade', $avisos[$this->rui->getId()][0]->getTitulo());
        self::assertSame('1 pasta(s) sua(s) sem processo precisam de atenção: 1180.', $avisos[$this->rui->getId()][0]->getMensagem());
        self::assertSame(0, $this->envios(), 'o envio manual não grava histórico (L4567)');
    }

    #[TestDox('Sem ninguém na Controladoria: só os primários recebem e a mensagem é a do desenho')]
    public function testSemControladoria(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET controladoria = false WHERE tenant_id = ?', [$this->tenant->getId()]);

        $resultado = $this->useCase()->executar($this->tenant, $this->sergio, false);

        self::assertTrue($resultado->semControladoria);
        self::assertSame('Nenhum usuário com perfil de Controladoria cadastrado.', $resultado->mensagem);
        $avisos = $this->avisosDoRelatorio($this->tenant);
        self::assertArrayNotHasKey((int) $this->carla->getId(), $avisos);
        self::assertArrayHasKey((int) $this->paula->getId(), $avisos);
    }

    #[TestDox('Quem é Controladoria e primário recebe um resumo só; vínculo inativo não recebe nada')]
    public function testSemRepetirENemInativo(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET controladoria = true WHERE user_id = ?', [$this->paula->getId()]);
        $this->em()->getConnection()->executeStatement('UPDATE user_tenant SET is_active = false WHERE user_id = ?', [$this->rui->getId()]);

        $this->useCase()->executar($this->tenant, $this->sergio, false);

        $avisos = $this->avisosDoRelatorio($this->tenant);
        $titulos = array_map(static fn ($a): string => $a->getTitulo(), $avisos[$this->paula->getId()]);
        self::assertSame(['Relatório mensal: 3 pasta(s) sem processo', 'Pastas sem processo: 1 sob sua responsabilidade'], $titulos);
        self::assertArrayNotHasKey((int) $this->rui->getId(), $avisos, 'responsável sem vínculo ativo fica de fora');
    }

    #[TestDox('Outro escritório não entra: nem as pastas na contagem, nem a Controladoria dele nos avisos')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $outro = $this->novoEscritorio('Outro');
        $alheia = $this->novaPessoa($outro, 'Controladoria Alheia', $this->novoPerfil($outro, false), controladoria: true);
        $this->novaPasta($outro, '9999', $alheia);

        $resultado = $this->useCase()->executar($this->tenant, $this->sergio, false);

        self::assertSame(3, $resultado->resumo?->sem);
        self::assertSame([], $this->avisosDoRelatorio($outro));
    }

    #[TestDox('Automático: na primeira segunda-feira a partir das 10h envia e grava o mês; no mesmo mês não repete')]
    public function testAutomaticoUmaVezPorMes(): void
    {
        $primeiro = $this->useCase()->executar($this->tenant, null, true);
        $segundo  = $this->useCase('2026-10-05 15:00:00')->executar($this->tenant, null, true);

        self::assertTrue($primeiro->enviado);
        self::assertSame(1, $this->envios());
        self::assertFalse($segundo->enviado);
        self::assertSame('O relatório deste mês já foi enviado.', $segundo->mensagem);
        self::assertArrayHasKey((int) $this->sergio->getId(), $this->avisosDoRelatorio($this->tenant), 'sem pessoa enviando, todos os primários recebem');
    }

    #[TestDox('Automático: fora da primeira segunda-feira, ou antes das 10h, não envia')]
    public function testAutomaticoForaDoDia(): void
    {
        $segundaSegunda = $this->useCase('2026-10-12 10:05:00')->executar($this->tenant, null, true);
        $terca          = $this->useCase('2026-10-06 10:05:00')->executar($this->tenant, null, true);
        $cedo           = $this->useCase('2026-10-05 09:30:00')->executar($this->tenant, null, true);

        self::assertSame('Hoje não é a primeira segunda-feira do mês.', $segundaSegunda->mensagem);
        self::assertSame('Hoje não é a primeira segunda-feira do mês.', $terca->mensagem);
        self::assertSame('O envio automático sai a partir das 10h.', $cedo->mensagem);
        self::assertSame(0, $this->envios());
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
    }

    #[TestDox('Com envio anterior, o aviso traz a evolução desde ele')]
    public function testEvolucaoDesdeOUltimoEnvio(): void
    {
        $this->em()->persist(new EnvioDoRelatorioSemProcesso($this->tenant, new ResumoSemProcesso(5, 3, 1, 2, 0), new \DateTimeImmutable('2026-09-07 10:05'), true));
        $this->em()->flush();

        $this->useCase()->executar($this->tenant, $this->sergio, false);

        $mensagem = (string) $this->avisosDoRelatorio($this->tenant)[$this->carla->getId()][0]->getMensagem();
        self::assertStringEndsWith(' Desde o último envio (09/2026): deveriam ter processo 3 → 1, rever cadastro 1 → 0, sem classificação 2 → 1.', $mensagem);
    }

    #[TestDox('Simular (--dry-run): conta os avisos, mas não grava aviso nem histórico')]
    public function testSimular(): void
    {
        $resultado = $this->useCase()->executar($this->tenant, null, true, simular: true);

        self::assertTrue($resultado->enviado);
        self::assertGreaterThan(0, $resultado->avisados);
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
        self::assertSame(0, $this->envios());
    }
}
