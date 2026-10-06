<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Lote L7 da auditoria 2 da Pasta (aba Push, desenho 1.2.3): U1 (ponto + cartão no mesmo
 * wrapper), U3 (cor da pílula pelo tipo), U4 (vazio em linha simples, texto mantido) e U8
 * (contagem sempre, inclusive o 0).
 *
 * Arranjo com combinador de FILHO DIRETO. Cor, raio e posição do ponto não são visíveis aqui —
 * ficam para o smoke na tela.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushAuditoria2TelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';

    #[TestDox('U1: ponto e cartão moram no mesmo wrapper; o teor fica fora dele, filho do <li>')]
    public function testPontoECartaoNoMesmoWrapper(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '70000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '70000002', self::NUMERO_DA_PASTA, '2026-08-20', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $item = '.ps-push-lista > .ps-push-item';
        self::assertCount(2, $crawler->filter($item . ' > .ps-push-cartao > .ps-push-ponto'));
        self::assertCount(2, $crawler->filter($item . ' > .ps-push-cartao > .ps-push-cab[aria-controls][data-push-url]'));
        self::assertCount(0, $crawler->filter($item . ' > .ps-push-ponto'), 'o ponto não se mede mais pelo <li>');
        self::assertCount(0, $crawler->filter($item . ' > .ps-push-cab'));
        self::assertCount(2, $crawler->filter($item . ' > .ps-push-teor[hidden]'));
        self::assertCount(0, $crawler->filter('.ps-push-cartao .ps-push-teor'));
        // A pílula do dia continua fora do cartão, filha do <li>.
        self::assertCount(2, $crawler->filter($item . ' > .ps-push-dia'));
    }

    #[TestDox('U3: a pílula do tipo ganha o modificador pela comunicação, sem depender de caixa ou acento')]
    public function testCorDaPilulaPeloTipo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);

        $esperado = [
            'Intimação'     => 'intimacao',
            'INTIMAÇÃO'     => 'intimacao',
            'Citação'       => 'citacao',
            'Despacho'      => 'decisao',
            'Decisão'       => 'decisao',
            'Sentença'      => 'decisao',
            'Edital'        => 'outro',
            'Solicitação'   => 'outro',
        ];
        $ids = [];
        $n   = 0;
        foreach ($esperado as $tipo => $mod) {
            $pub = $this->criarPublicacao($tenant, (string) (71000000 + ++$n), self::NUMERO_DA_PASTA, '2026-08-20', $processo);
            $pub->setTipoComunicacao($tipo);
            $ids[$pub->getId()] = [$pub->getTipoComunicacao(), $mod];
        }
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        foreach ($ids as $id => [$tipo, $mod]) {
            $pilula = $crawler->filter(sprintf(
                '.ps-push-lista > .ps-push-item[data-push-id="%s"] > .ps-push-cartao > .ps-push-cab > .ps-push-corpo > .ps-push-titulo',
                $id,
            ));
            self::assertCount(1, $pilula, (string) $tipo);
            self::assertSame($tipo, trim($pilula->text()));
            $classes = preg_split('/\s+/', trim((string) $pilula->attr('class')));
            self::assertContains('ps-push-titulo--' . $mod, $classes, (string) $tipo);
            self::assertCount(1, array_filter($classes, static fn (string $c): bool => str_starts_with($c, 'ps-push-titulo--')), (string) $tipo);
        }
    }

    #[TestDox('U4 + U8: sem processo, vazio em linha (sem ícone nem título) e contagem 0 no cabeçalho')]
    public function testVazioSemProcessoEmLinhaComContagemZero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $vazio = $crawler->filter('#push > .ps-push > #push-vazio-sem-processo.ps-push-vazio-linha');
        self::assertCount(1, $vazio);
        self::assertCount(0, $vazio->filter('i, .ps-vazio-titulo, .ps-vazio-nota'));
        self::assertStringContainsString('Esta pasta não tem processo vinculado', $vazio->text());
        // O atalho para a aba Processo continua no texto.
        self::assertCount(1, $vazio->filter('button.ps-push-link[data-ps-ir-aba="processo-tab"]'));
        self::assertCount(0, $crawler->filter('#push > .ps-push > .ps-vazio'));

        self::assertSame('0', trim($crawler->filter('#push > .ps-push > .ps-card-cab > .ps-contagem')->text()));
    }

    #[TestDox('U4 + U8: com processo e sem publicação, o outro vazio em linha e contagem 0 — publicação de OUTRO escritório não conta')]
    public function testVazioSemPublicacaoNaoContaOutroEscritorio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));

        // Mesmo número de processo, mas publicação de outro escritório.
        $alheio = $this->criarTenant();
        $this->criarPublicacao($alheio, '72000001', self::NUMERO_DA_PASTA, '2026-08-20');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        $vazio = $crawler->filter('#push > .ps-push > #push-vazio-sem-publicacao.ps-push-vazio-linha');
        self::assertCount(1, $vazio);
        self::assertStringContainsString('Nenhuma publicação captada para este caso', $vazio->text());
        self::assertCount(0, $crawler->filter('.ps-push-lista'));
        self::assertSame('0', trim($crawler->filter('#push > .ps-push > .ps-card-cab > .ps-contagem')->text()));
    }

    #[TestDox('U4 + U8: com publicação, a contagem mostra o total e o vazio do filtro é linha simples escondida')]
    public function testContagemComPublicacaoEVazioDoFiltro(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, '73000001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $this->criarPublicacao($tenant, '73000002', self::NUMERO_DA_PASTA, '2026-08-21', $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        self::assertSame('2', trim($crawler->filter('#push > .ps-push > .ps-card-cab > .ps-contagem')->text()));
        $filtro = $crawler->filter('#push > .ps-push > #push-vazio-filtro.ps-push-vazio-linha[hidden]');
        self::assertCount(1, $filtro);
        self::assertSame('Nenhuma movimentação neste filtro.', trim($filtro->text()));
    }
}
