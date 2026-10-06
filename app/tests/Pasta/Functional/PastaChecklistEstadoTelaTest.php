<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MotivoDesativacaoChecklist;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Twig\DocumentosSugeridosExtension;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Estado do checklist na TELA (DOC-73, dc L2079-2117): o interruptor "Ativo" no cabeçalho, a
 * confirmação com os quatro motivos, a faixa "Checklist desativado" e a pendência da aba que some.
 *
 * Arranjo por combinador de FILHO DIRETO (`#pexChecklist > .pex-ck-cab > #btnChecklistEstado`):
 * distingue "o interruptor está no cabeçalho do cartão" de "existe um interruptor em algum lugar".
 * Estilo (cores do trilho, roxo da confirmação) é do smoke.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(DocumentosSugeridosExtension::class)]
final class PastaChecklistEstadoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function item(Pasta $pasta, Tenant $tenant, string $titulo, bool $concluido, int $ordem): void
    {
        $this->em()->persist((new PastaChecklistItem())
            ->setPasta($pasta)
            ->setTenant($tenant)
            ->setTitulo($titulo)
            ->setConcluido($concluido)
            ->setOrdem($ordem));
        $this->em()->flush();
    }

    private function abrir(KernelBrowser $client, int $pastaId): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('ativo: o interruptor é filho direto do cabeçalho, ligado, depois das ações; a confirmação nasce escondida com os 4 motivos do desenho')]
    public function testArranjoAtivo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        $sw = $crawler->filter('#pexChecklist > .pex-ck-cab > #btnChecklistEstado');
        self::assertSame(1, $sw->count());
        self::assertSame('switch', $sw->attr('role'));
        self::assertSame('true', $sw->attr('aria-checked'));
        self::assertSame('Ativo', trim($sw->filter('.pex-ck-interruptor-txt')->text()));
        self::assertSame(1, $sw->filter('#btnChecklistEstado > .pex-ck-interruptor-trilho > .pex-ck-interruptor-bola')->count());

        // Último do cabeçalho, como no desenho (L2093): depois do "+".
        $filhos = $crawler->filter('#pexChecklist > .pex-ck-cab > *');
        self::assertSame('btnChecklistEstado', $filhos->last()->attr('id'));
        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-cab > #btnChecklistAdicionar')->count());

        $painel = $crawler->filter('#pexChecklist > #checklistDesativarPainel');
        self::assertSame(1, $painel->count(), 'a confirmação é irmã do cabeçalho, logo abaixo dele');
        self::assertNotNull($painel->attr('hidden'), 'nasce escondida');
        self::assertSame(
            ['Não se aplica a esta pasta', 'Documentos controlados em outro sistema', 'Pasta administrativa ou consultiva', 'Pasta encerrada'],
            $painel->filter('.pex-ck-desativar-motivos > button.pex-ck-motivo')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertSame(
            array_map(static fn (MotivoDesativacaoChecklist $m): string => $m->value, MotivoDesativacaoChecklist::cases()),
            $painel->filter('.pex-ck-desativar-motivos > button.pex-ck-motivo')->each(static fn (Crawler $b): string => (string) $b->attr('data-motivo')),
        );
        self::assertNotNull($painel->filter('.pex-ck-desativar-acoes > #btnChecklistDesativar')->attr('disabled'), 'sem motivo não desativa');
        self::assertSame(1, $painel->filter('.pex-ck-desativar-acoes > #btnChecklistManterAtivo')->count());

        // Ordem do cartão: cabeçalho → confirmação → corpo.
        $irmaos = $crawler->filter('#pexChecklist > div')->each(static fn (Crawler $c): string => (string) ($c->attr('id') ?: $c->attr('class')));
        self::assertSame(['pex-ck-cab', 'checklistDesativarPainel', 'pex-ck-corpo'], $irmaos);

        $raiz = $crawler->filter('#pexChecklist');
        self::assertSame('/pasta/' . $pasta->getId() . '/checklist/estado', $raiz->attr('data-url-estado'));
        self::assertSame('1', $raiz->attr('data-checklist-ativo'));
    }

    #[TestDox('desativado: faixa com motivo, quem e quando + Reativar; sem corpo, sem ações; nada de 30 dias, cobrança ou IA')]
    public function testArranjoDesativado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->item($pasta, $tenant, 'Procuração', true, 0);
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::OutroSistema, $user, new \DateTimeImmutable('2026-10-02 09:00'));
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        $sw = $crawler->filter('#pexChecklist > .pex-ck-cab > #btnChecklistEstado');
        self::assertSame('false', $sw->attr('aria-checked'));
        self::assertSame('Desativado', trim($sw->filter('.pex-ck-interruptor-txt')->text()));

        $faixa = $crawler->filter('#pexChecklist > #checklistDesativado');
        self::assertSame(1, $faixa->count());
        self::assertSame(1, $faixa->filter('#checklistDesativado > i.bi-pause-circle')->count());
        self::assertSame(1, $faixa->filter('#checklistDesativado > #btnChecklistReativar')->count());
        $texto = preg_replace('/\s+/u', ' ', $faixa->filter('.pex-ck-desativado-texto')->text());
        self::assertStringContainsString('Checklist desativado · Documentos controlados em outro sistema · Admin Push em 02/10/2026', (string) $texto);

        self::assertSame(0, $crawler->filter('#pexChecklist > .pex-ck-corpo')->count(), 'desativado não desenha os itens (dc L2172 sob ckc.ativo)');
        self::assertSame(0, $crawler->filter('#pexChecklist #checklistDesativarPainel')->count());
        foreach (['#btnChecklistAdicionar', '#btnChecklistEditar', '#btnChecklistModelos', '#btnDocumentosSugeridos', '#documentosSugeridos'] as $id) {
            self::assertSame(0, $crawler->filter('#pexChecklist ' . $id)->count(), "{$id} some com o checklist desativado");
        }

        $cartao = $crawler->filter('#pexChecklist')->text();
        self::assertStringNotContainsString('30 dias', $cartao, 'não existe lembrete (S-6)');
        self::assertStringNotContainsStringIgnoringCase('cobran', $cartao, 'não existe cobrança (S-6)');
        self::assertStringNotContainsString('controladoria', $cartao);
        self::assertDoesNotMatchRegularExpression('/\bIA\b/u', $cartao);
        self::assertSame('0', $crawler->filter('#pexChecklist')->attr('data-checklist-ativo'));
    }

    #[TestDox('pendência "sem anexo" da aba Documentos: acende com o checklist ativo e SOME quando desativado')]
    public function testPendenciaSomeQuandoDesativado(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->item($pasta, $tenant, 'Contrato de honorários', true, 0);
        $pastaId = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        // Recurso irmão: a MESMA pasta, ativa, tem a pendência — o "some" abaixo é o estado.
        $ativa = $this->abrir($client, $pastaId);
        self::assertSame(1, $ativa->filter('#pastaTabs > #documentos-tab.ps-aba--pend')->count());

        // O abrir() limpou o EntityManager: pasta e usuário são relidos para a FK não apontar a entidade solta.
        $pasta = $this->em()->find(Pasta::class, $pastaId);
        $autor = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($pasta);
        self::assertNotNull($autor);
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::Encerrada, $autor, new \DateTimeImmutable());
        $this->em()->flush();

        $desativada = $this->abrir($client, $pastaId);
        self::assertSame(0, $desativada->filter('#pastaTabs > #documentos-tab.ps-aba--pend')->count());
        self::assertSame(0, $desativada->filter('#documentos-tab > .ps-aba-pend')->count());
        self::assertSame(0, $desativada->filter('.pex-ck-sem-anexo')->count());
    }

    #[TestDox('motivo nulo (usuário removido) não quebra a faixa: diz "usuário removido"')]
    public function testDesativadoPorUsuarioRemovido(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->desativarChecklist(MotivoDesativacaoChecklist::Encerrada, $user, new \DateTimeImmutable('2026-10-01'));
        $this->em()->flush();
        // Simula o ON DELETE SET NULL sem apagar o usuário logado.
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET checklist_desativado_por_id = NULL WHERE id = :id', ['id' => $pasta->getId()]);
        $this->logarComTenant($client, $user, $tenant);

        $faixa = $this->abrir($client, (int) $pasta->getId())->filter('#pexChecklist > #checklistDesativado');

        self::assertStringContainsString('usuário removido em 01/10/2026', (string) preg_replace('/\s+/u', ' ', $faixa->text()));
    }
}
