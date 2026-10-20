<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\PastaParada;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\AnalisadorDePastasParadas;
use App\Pasta\Service\ColetorDePastasParadas;
use App\Pasta\Service\MontagemDoRelatorioPastasParadas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As fontes do relatório de pastas paradas lidas do banco, em lote e com o escritório explícito
 * (sem request, como no comando): movimentação do processo, Push, registro de pessoa, andamento,
 * documentos e pagamentos (spec docs/specs/pastas-paradas.md, "Cálculo").
 */
#[CoversClass(ColetorDePastasParadas::class)]
#[CoversClass(MontagemDoRelatorioPastasParadas::class)]
#[Group('pasta')]
final class ColetorDePastasParadasTest extends KernelTestCase
{
    use CriaEscritorioPastasParadasTrait;

    private const HOJE = '2026-10-05 10:05:00';

    private Tenant $tenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tenant = $this->novoEscritorio('Coletor');
        $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
    }

    /** @return array<string, PastaParada> número => pasta parada */
    private function paradas(?Tenant $tenant = null): array
    {
        $this->em()->clear();
        $tenant   = $this->em()->find(Tenant::class, ($tenant ?? $this->tenant)->getId());
        self::assertNotNull($tenant);
        $montagem = static::getContainer()->get(MontagemDoRelatorioPastasParadas::class);

        $porNumero = [];
        foreach ($montagem->montar($tenant, null, null, new \DateTimeImmutable(self::HOJE))->pastas as $p) {
            $porNumero[$p->numero] = $p;
        }

        return $porNumero;
    }

    #[TestDox('Movimentação do processo, Push e registro recentes tiram a pasta da lista; a mais recente vale')]
    public function testFontesDeMovimentacao(): void
    {
        $porMov = $this->pastaAbertaEm($this->tenant, '1', '2026-01-10');
        $this->movimentar($this->processoDaPasta($porMov, '07011345720258070001'), '2026-09-25');
        $porPush = $this->pastaAbertaEm($this->tenant, '2', '2026-01-10');
        $this->processoDaPasta($porPush, '07011345720258070002');
        $this->publicar($this->tenant, '0701134-57.2025.8.07.0002', '2026-09-28');
        $porRegistro = $this->pastaAbertaEm($this->tenant, '3', '2026-01-10');
        $this->registrarEm($porRegistro, '2026-09-30 18:00:00');

        $todas = $this->pastaAbertaEm($this->tenant, '4', '2026-01-10');
        $proc  = $this->processoDaPasta($todas, '07011345720258070004');
        $this->movimentar($proc, '2026-06-10');
        $this->publicar($this->tenant, '07011345720258070004', '2026-07-20');
        $this->registrarEm($todas, '2026-05-01 09:00:00');
        $this->andamento($todas, 5, '2026-07-01T14:00:00-03:00');

        $paradas = $this->paradas();

        self::assertSame(['4'], array_map('strval', array_keys($paradas)));
        self::assertSame('20/07/2026', $paradas['4']->ultima?->format('d/m/Y'));
        self::assertSame(77, $paradas['4']->dias);
        self::assertContains('Andamento: na fase "Em andamento" há 96 dias (desde 01/07/2026).', $paradas['4']->pontos);
        self::assertSame('0701134-57.2025.8.07.0004', $paradas['4']->processoNumero);
        self::assertSame('TJDFT', $paradas['4']->processoTribunal);
    }

    #[TestDox('Registro do sistema não conta como movimentação; o Push de outro escritório também não')]
    public function testRegistroDoSistemaEPushAlheioNaoContam(): void
    {
        $pasta = $this->pastaAbertaEm($this->tenant, '10', '2026-03-01');
        $this->processoDaPasta($pasta, '07011345720258070010');
        $this->registrarEm($pasta, '2026-10-01 10:00:00');
        $this->em()->getConnection()->executeStatement("UPDATE pasta_mensagem SET sistema = 'fase' WHERE pasta_id = ?", [$pasta->getId()]);
        $this->publicar($this->novoEscritorio('Outro'), '07011345720258070010', '2026-10-02');

        $paradas = $this->paradas();

        self::assertArrayHasKey('10', $paradas);
        self::assertNull($paradas['10']->ultima);
    }

    #[TestDox('Andamento na última fase tira a pasta; sem andamento, processo arquivado também')]
    public function testEncerradas(): void
    {
        $this->andamento($this->pastaAbertaEm($this->tenant, '20', '2026-01-10'), 9, '2026-02-01T10:00:00-03:00');
        $this->processoDaPasta($this->pastaAbertaEm($this->tenant, '21', '2026-01-10'), '07011345720258070021', 'Arquivado definitivamente');
        $this->processoDaPasta($this->pastaAbertaEm($this->tenant, '22', '2026-01-10'), '07011345720258070022', 'Suspenso');

        $paradas = $this->paradas();

        self::assertSame(['22'], array_map('strval', array_keys($paradas)));
        self::assertContains('Processo 0701134-57.2025.8.07.0022 consta como suspenso.', $paradas['22']->pontos);
    }

    #[TestDox('Documentos: procuração pela categoria, contrato de honorários pelo título; a lixeira não conta')]
    public function testDocumentos(): void
    {
        $completa = $this->pastaAbertaEm($this->tenant, '30', '2026-01-10');
        $this->documento($completa, 'Instrumento de mandato', PastaDocumento::CATEGORIA_PROCURACAO);
        $this->documento($completa, 'Contrato de honorários assinado');
        $semProcuracao = $this->pastaAbertaEm($this->tenant, '31', '2026-01-10');
        $this->documento($semProcuracao, 'Contrato', PastaDocumento::CATEGORIA_CONTRATO);
        $this->documento($semProcuracao, 'Procuração antiga', PastaDocumento::CATEGORIA_PROCURACAO);
        $this->em()->getConnection()->executeStatement("UPDATE pasta_documento SET excluido_em = NOW() WHERE titulo = 'PROCURAÇÃO ANTIGA'");

        $paradas = $this->paradas();

        self::assertNotContains(AnalisadorDePastasParadas::SUGESTAO_DOCUMENTOS, $paradas['30']->sugestoes);
        self::assertContains('Falta juntar: Procuração.', $paradas['31']->pontos);
    }

    #[TestDox('Pagamentos: o resumo conta vencidos (pendentes antes de hoje) e ignora os pagos')]
    public function testPagamentos(): void
    {
        $pasta = $this->pastaAbertaEm($this->tenant, '40', '2026-01-10');
        $this->pagamento($pasta, '2026-09-01');
        $this->pagamento($pasta, '2026-08-01', pago: true);
        $this->pagamento($pasta, '2026-11-01');
        $emDia = $this->pastaAbertaEm($this->tenant, '41', '2026-01-10');
        $this->pagamento($emDia, '2026-10-05');

        $this->em()->clear();
        $tenant  = $this->em()->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($tenant);
        $coleta  = static::getContainer()->get(ColetorDePastasParadas::class)->coletar($tenant, null, new \DateTimeImmutable(self::HOJE));
        $porNumero = [];
        foreach ($coleta as $p) {
            $porNumero[$p->numero] = $p->pagamentos;
        }

        self::assertSame(['total' => 3, 'vencidos' => 1], $porNumero['40']);
        self::assertSame(['total' => 1, 'vencidos' => 0], $porNumero['41'], 'vence hoje: ainda não venceu');
    }

    #[TestDox('Só as pastas ativas e não excluídas do escritório pedido')]
    public function testSoAsAtivasDoEscritorio(): void
    {
        $this->pastaAbertaEm($this->tenant, '50', '2026-01-10');
        $arquivada = $this->pastaAbertaEm($this->tenant, '51', '2026-01-10');
        $this->em()->getConnection()->executeStatement("UPDATE pasta SET situacao = 'arquivado' WHERE id = ?", [$arquivada->getId()]);
        $excluida = $this->pastaAbertaEm($this->tenant, '52', '2026-01-10');
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET excluida_em = NOW() WHERE id = ?', [$excluida->getId()]);
        $this->pastaAbertaEm($this->novoEscritorio('Alheio'), '53', '2026-01-10');

        self::assertSame(['50'], array_map('strval', array_keys($this->paradas())));
    }
}
