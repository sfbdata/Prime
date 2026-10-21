<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * a6 (spec processo-cadastro-pela-pasta): "Encaminhar via Chat I.A" no ⋮ do cartão do processo, na posição do
 * desenho (procMenu dc L3069: depois de Compartilhar, antes de Relatório geral de processos), levando só
 * identificadores para o `pasta-encaminhar.js` (`bj-central:despachar`, spec encaminhar-via-chat-ia §4.1).
 * Sem a Central (vínculo inativo) e na lápide, o item não é montado. A tela é do smoke do dono.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaEncaminharProcessoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /** @return array{Crawler, Pasta, Processo} */
    private function abrir(bool $comCentral = true, bool $lapide = false): array
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, '07011345720258070007');
        $this->vincular($pasta, $processo);
        if ($lapide) {
            $pasta->marcarExcluida($user, new \DateTimeImmutable());
            $this->em()->flush();
        }

        $this->logarComTenant($client, $user, $tenant);
        if (!$comCentral) {
            $this->em()->getConnection()->executeStatement(
                'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
                [$user->getId(), $tenant->getId()],
            );
        }

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return [$crawler, $pasta, $processo];
    }

    #[TestDox('o item vem depois de Compartilhar e antes do Relatório, com tipo processo, o id do processo e o da pasta')]
    public function testItemNaPosicaoDoDesenho(): void
    {
        [$crawler, $pasta, $processo] = $this->abrir();
        $menu = '#psProcMenu' . $processo->getId();

        $item = $crawler->filter("{$menu} > .js-proc-compartilhar + button.js-encaminhar");
        self::assertCount(1, $item, 'Compartilhar, Encaminhar: a ordem do desenho');
        self::assertCount(1, $crawler->filter("{$menu} > button.js-encaminhar + .js-relatorio-processos"), 'Encaminhar, Relatório');
        self::assertSame('Encaminhar via Chat I.A', trim($item->text()));
        self::assertCount(1, $item->filter('button > i.bi.bi-send'));
        self::assertSame('menuitem', $item->attr('role'));
        self::assertSame('processo', $item->attr('data-encaminhar-tipo'));
        self::assertSame((string) $processo->getId(), $item->attr('data-encaminhar-id'));
        self::assertSame((string) $pasta->getId(), $item->attr('data-encaminhar-pasta'));
    }

    #[TestDox('sem a Central (vínculo inativo): o item não aparece; o resto do ⋮ continua')]
    public function testSemCentral(): void
    {
        [$crawler, , $processo] = $this->abrir(comCentral: false);

        self::assertCount(0, $crawler->filter('#psProcMenu' . $processo->getId() . ' .js-encaminhar'));
        self::assertCount(1, $crawler->filter('#psProcMenu' . $processo->getId() . ' > .js-proc-compartilhar'));
    }

    #[TestDox('na lápide: o item não aparece')]
    public function testNaLapide(): void
    {
        [$crawler, , $processo] = $this->abrir(lapide: true);

        self::assertCount(0, $crawler->filter('#psProcMenu' . $processo->getId() . ' .js-encaminhar'));
    }
}
