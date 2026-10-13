<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * FPN-019: a cor do ponto da linha do tempo do Push diz quem praticou o ato (desenho 1.2.3,
 * dc L.5365 `COR`): juízo em azul, cartório/servidor em vermelho, com a dica do desenho. A cor em
 * si é do smoke; aqui se prova que a classe e a dica saem pelo tipo, sem depender de caixa ou
 * acento, e que o ponto continua no wrapper do cartão.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaPushPontoPorTipoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const DICA_JUIZO      = 'Ato do juízo (decisão, despacho, sentença, mandado)';
    private const DICA_CARTORIO   = 'Cartório / servidor (certidão, diligência, intimação)';

    #[TestDox('O ponto ganha o modificador e a dica pelo tipo da comunicação')]
    public function testPontoPeloTipo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);

        $esperado = [
            'Decisão'                 => 'juizo',
            'DESPACHO'                => 'juizo',
            'Sentença'                => 'juizo',
            'Mandado'                 => 'juizo',
            'Acórdão'                 => 'juizo',
            'Intimação'               => 'cartorio',
            'Edital'                  => 'cartorio',
            'Certidão'                => 'cartorio',
            'Citação'                 => 'cartorio',
            'Lista de Distribuição'   => 'cartorio',
        ];
        $ids = [];
        $n   = 0;
        foreach ($esperado as $tipo => $mod) {
            $pub = $this->criarPublicacao($tenant, (string) (74000000 + ++$n), self::NUMERO_DA_PASTA, '2026-08-20', $processo);
            $pub->setTipoComunicacao($tipo);
            $ids[(int) $pub->getId()] = [$tipo, $mod];
        }
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");
        self::assertResponseIsSuccessful();

        foreach ($ids as $id => [$tipo, $mod]) {
            $ponto = $crawler->filter('.ps-push-lista > .ps-push-item[data-push-id="' . $id . '"] > .ps-push-cartao > .ps-push-ponto');
            self::assertCount(1, $ponto, "o ponto de \"{$tipo}\" segue filho do cartão");
            $classes = explode(' ', (string) $ponto->attr('class'));
            self::assertContains('ps-push-ponto--' . $mod, $classes, "\"{$tipo}\" deveria ser {$mod}");
            self::assertNotContains('ps-push-ponto--' . ($mod === 'juizo' ? 'cartorio' : 'juizo'), $classes);
            self::assertSame($mod === 'juizo' ? self::DICA_JUIZO : self::DICA_CARTORIO, $ponto->attr('title'), "dica de \"{$tipo}\"");
            self::assertSame('true', $ponto->attr('aria-hidden'), 'o ponto é decorativo para o leitor de tela');
        }
    }

    #[TestDox('Publicação sem tipo cai em cartório, como no desenho (o que tem documento e não é do juízo)')]
    public function testSemTipoCaiEmCartorio(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, '74100001', self::NUMERO_DA_PASTA, '2026-08-20', $processo);
        $pub->setTipoComunicacao(null);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}");

        $ponto = $crawler->filter('.ps-push-item[data-push-id="' . $pub->getId() . '"] .ps-push-ponto');
        self::assertStringContainsString('ps-push-ponto--cartorio', (string) $ponto->attr('class'));
    }
}
