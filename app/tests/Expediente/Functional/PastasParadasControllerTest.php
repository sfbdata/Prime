<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Controller\PastasParadasController;
use App\Pasta\DTO\RelatorioPastasParadasOutput;
use App\Pasta\UseCase\GerarRelatorioPastasParadasUseCase;
use App\Shared\Service\GeradorDePdf;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaEscritorioPastasParadasTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Página, fragmento e PDF do relatório de pastas paradas (spec docs/specs/pastas-paradas.md,
 * "Página e PDF"; desenho `paradasHTML`).
 *
 * Escritório: Paula (primária), Carla (Controladoria, só com o módulo Expediente), Vera (só
 * Expediente, com acesso item a item à 1181) e Nina (sem o módulo). Pastas 1180 (da Paula, aberta
 * há 100 dias, com um pagamento vencido), 1181 (da Vera, aberta há 50 dias), 1183 (aberta há 10 dias:
 * não está parada) e 1182 (excluída). Outro escritório tem a 7777, parada há 100 dias.
 * O relógio é o real: as datas são relativas a hoje.
 */
#[CoversClass(PastasParadasController::class)]
#[CoversClass(GerarRelatorioPastasParadasUseCase::class)]
#[Group('expediente')]
final class PastasParadasControllerTest extends JusPrimeWebTestCase
{
    use CriaEscritorioPastasParadasTrait;

    private const URL = '/expediente/pastas-paradas';

    private KernelBrowser $client;
    private Tenant $tenant;
    private User $paula;
    private User $carla;
    private User $vera;
    private User $nina;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tenant = $this->novoEscritorio('Paradas');
        $soExpediente = $this->novoPerfil($this->tenant, false, ['modules.expediente.view']);

        $this->paula = $this->novaPessoa($this->tenant, 'Paula Primária', $this->novoPerfil($this->tenant, true));
        $this->carla = $this->novaPessoa($this->tenant, 'Carla Controladoria', $soExpediente, controladoria: true);
        $this->vera  = $this->novaPessoa($this->tenant, 'Vera Restrita', $soExpediente);
        $this->nina  = $this->novaPessoa($this->tenant, 'Nina Sem Módulo', $this->novoPerfil($this->tenant, false, ['resources.pasta.view']));

        $p1180 = $this->pastaAbertaEm($this->tenant, '1180', '-100 days', $this->paula);
        $this->pagamento($p1180, '-50 days');
        $p1181 = $this->pastaAbertaEm($this->tenant, '1181', '-50 days', $this->vera);
        $this->darAcessoAPasta($this->vera, $p1181);
        $this->pastaAbertaEm($this->tenant, '1183', '-10 days', $this->paula);
        $excluida = $this->pastaAbertaEm($this->tenant, '1182', '-100 days', $this->paula);
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET excluida_em = NOW() WHERE id = ?', [$excluida->getId()]);

        $outro = $this->novoEscritorio('Outro');
        $this->pastaAbertaEm($outro, '7777', '-100 days');
    }

    private function abrir(User $quem, string $sufixo = '?fragmento=1'): string
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $this->client->request('GET', self::URL . $sufixo);

        return (string) $this->client->getResponse()->getContent();
    }

    #[TestDox('Folha: título, os 4 cartões, o texto de abertura, a tabela, o cartão por pasta e o rodapé sem I.A')]
    public function testFolhaDoPrimario(): void
    {
        $html = $this->abrir($this->paula);

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('.rp-documento > main.rp-folha'));
        self::assertStringStartsWith('Pastas paradas há mais de 30 dias · ', (string) $crawler->filter('main.rp-folha')->attr('data-rp-titulo'));
        self::assertSame(
            ['Pastas paradas', 'Mais de 90 dias', 'Sem contrato', 'Sem processo'],
            $crawler->filter('.rp-corpo > .ppd-cartoes-moldura > table.ppd-cartoes td.ppd-cartao > .ppd-cartao-rotulo')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame(['2', '1', '2', '2'], $crawler->filter('.ppd-cartao-valor')->each(static fn ($n): string => trim($n->text())));
        self::assertStringContainsString('Para cada uma, informe ao responsável se a pasta deve <b>receber movimentação</b> ou ser <b>arquivada</b>.', $html);
        self::assertSame(
            ['Pasta', 'Cliente', 'Responsável', 'Processo', 'Última mov.', 'Parada'],
            $crawler->filter('.rp-corpo > table.ppd-tabela > thead th')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame(['1180', '1181'], $crawler->filter('.rp-corpo > table.ppd-tabela > tbody td.ppd-num')->each(static fn ($n): string => trim($n->text())));
        self::assertSame('⏸ 100 dias', trim($crawler->filter('.ppd-tabela tbody tr')->first()->filter('.ppd-chip')->text()));
        self::assertCount(2, $crawler->filter('.rp-corpo > .ppd-pasta'));
        self::assertStringContainsString('⏸ Pasta 1180 · CLIENTE 1180', $crawler->filter('.rp-corpo > .ppd-pasta')->first()->text());
        self::assertSame('Crítico', trim($crawler->filter('.rp-corpo > .ppd-pasta.ppd-pasta--critico .ppd-chip')->text()));
        self::assertStringContainsString('Responsável pela decisão: ____________________', $html);
        self::assertSame(RelatorioPastasParadasOutput::NOTA_FINAL, trim($crawler->filter('.rp-corpo > p.ppd-nota-final')->text()));
        self::assertDoesNotMatchRegularExpression('/\bI\.?A\b/u', $crawler->filter('main.rp-folha')->text());
        self::assertStringNotContainsString('7777', $html, 'pasta de outro escritório');
        self::assertStringNotContainsString('1182', $html, 'pasta excluída');
        self::assertStringNotContainsString('1183', $html, 'pasta aberta há 10 dias não está parada');
        self::assertStringNotContainsString('<script', $html, 'o fragmento não leva script');
    }

    #[TestDox('Página própria (link do aviso): barra com Baixar PDF e a mesma folha')]
    public function testPaginaPropria(): void
    {
        $this->abrir($this->carla, '');

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('body > .rp-barra a[href="' . self::URL . '/pdf"]'));
        self::assertCount(1, $crawler->filter('body > main.rp-folha'));
    }

    #[TestDox('Financeiro: a primária vê a linha e a sugestão; a Controladoria sem permissão de ver a pasta, não')]
    public function testFinanceiroSoParaQuemPodeVer(): void
    {
        $daPrimaria = $this->abrir($this->paula);
        self::assertStringContainsString('Financeiro: 1 pagamento(s) vencido(s).', $daPrimaria);
        self::assertStringContainsString('Ver com o Financeiro se o pagamento já foi feito e dar a baixa no sistema.', $daPrimaria);

        $daControladoria = $this->abrir($this->carla);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1180', $daControladoria, 'a Controladoria vê todas as pastas');
        self::assertStringNotContainsString('Financeiro:', $daControladoria);
        self::assertStringNotContainsString('Ver com o Financeiro', $daControladoria);
    }

    #[TestDox('Visibilidade: quem só tem acesso item a item vê só as pastas dele')]
    public function testLeitorSoVeAsPastasQuePode(): void
    {
        $html = $this->abrir($this->vera);

        self::assertResponseIsSuccessful();
        self::assertSame(['1181'], $this->client->getCrawler()->filter('.ppd-tabela td.ppd-num')->each(static fn ($n): string => trim($n->text())));
        self::assertStringNotContainsString('Paula Primária', $html);
    }

    #[TestDox('Sem pasta parada: a página diz que não há nenhuma')]
    public function testSemPastaParada(): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET data_abertura = NOW() WHERE tenant_id = ?', [$this->tenant->getId()]);
        $this->em()->clear();

        $html = $this->abrir($this->paula);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma pasta parada há mais de 30 dias. 👍', $html);
        self::assertCount(0, $this->client->getCrawler()->filter('.ppd-tabela'));
    }

    #[TestDox('Sem o módulo Expediente: 403 na página e no PDF')]
    public function testSemModulo403(): void
    {
        $this->abrir($this->nina, '');
        self::assertResponseStatusCodeSame(403);

        $this->abrir($this->nina, '/pdf');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('PDF: 200, application/pdf, anexo com o nome do desenho')]
    public function testPdf(): void
    {
        $this->abrir($this->paula, '/pdf');

        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $resposta->getContent());
        self::assertStringContainsString('attachment', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('BlueJus Pastas paradas h', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString((new \DateTimeImmutable())->format('d.m.Y') . '.pdf', (string) $resposta->headers->get('Content-Disposition'));
    }

    #[TestDox('Pasta parada de outro escritório não aparece na página nem no PDF')]
    public function testOutroEscritorioNaoApareceNaPaginaNemNoPdf(): void
    {
        $alheio = $this->novoEscritorio('Alheio');
        $this->pastaAbertaEm($alheio, '8888', '-200 days');

        // O PDF sai comprimido: um espião no lugar do gerador guarda o HTML do modo pdf.
        $espiao = new class () implements GeradorDePdf {
            public string $html = '';

            public function gerar(string $html): string
            {
                $this->html = $html;

                return '%PDF-espiao';
            }

            public function logoEmbutido(): ?string
            {
                return null;
            }

            public function resposta(string $pdf, string $titulo, \DateTimeImmutable $hoje): Response
            {
                return new Response($pdf, Response::HTTP_OK, ['Content-Type' => 'application/pdf']);
            }
        };
        $this->client->disableReboot();
        static::getContainer()->set(GeradorDePdf::class, $espiao);
        $this->abrir($this->paula, '/pdf');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<!DOCTYPE html>', $espiao->html);
        self::assertStringContainsString('>1180<', $espiao->html);
        self::assertStringNotContainsString('8888', $espiao->html);
        self::assertStringNotContainsString('7777', $espiao->html);

        $pagina = $this->abrir($this->paula, '');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1180', $pagina);
        self::assertStringNotContainsString('8888', $pagina);
        self::assertStringNotContainsString('7777', $pagina);
    }
}
