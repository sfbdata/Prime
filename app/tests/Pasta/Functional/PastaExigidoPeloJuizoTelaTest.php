<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\DeterminacoesDoJuizo;
use App\Pasta\Twig\DocumentosSugeridosExtension;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Exigido pelo juízo" no painel de sugeridos (DOC-79), de ponta a ponta: publicação captada do
 * DJEN → leitura por regras → item 🔴 com "Origem" apontando o teor no acordeão da aba Push.
 *
 * Isolamento provado com recursos IRMÃOS: a publicação do MESMO escritório com o número da pasta
 * entra; a do mesmo escritório com o número de OUTRA pasta não entra; a de OUTRO escritório com o
 * MESMO número não entra. Cada uma pede um documento diferente, para o teste saber qual entrou.
 */
#[CoversClass(DeterminacoesDoJuizo::class)]
#[CoversClass(DocumentosSugeridosExtension::class)]
final class PastaExigidoPeloJuizoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO_DA_PASTA = '07011345720258070007';
    private const NUMERO_DE_OUTRA = '07099999920258070001';

    private function publicar(Tenant $tenant, string $numero, string $djenId, string $texto, \DateTimeImmutable $data, ?Processo $processo = null): PublicacaoDjen
    {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId($djenId);
        $pub->setNumeroComunicacao('4' . $djenId);
        $pub->setNumeroProcesso($numero);
        $pub->setSiglaTribunal('TJDFT');
        $pub->setTipoComunicacao('Intimação');
        $pub->setTipoDocumento('Decisão');
        $pub->setNomeOrgao('3ª Vara Cível de Brasília');
        $pub->setDataDisponibilizacao($data);
        $pub->setTexto($texto);
        if ($processo !== null) {
            $pub->setProcesso($processo);
        }
        $this->em()->persist($pub);
        $this->em()->flush();

        return $pub;
    }

    private function abrir(KernelBrowser $client, int $pastaId): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('a decisão da PRÓPRIA pasta vira "Exigido pelo juízo" com origem e prazo; a de outra pasta e a de outro escritório não entram')]
    public function testExigidoComOrigemEIsolamento(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();

        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Indenização por danos morais');
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);

        $ontem = new \DateTimeImmutable('yesterday');
        $daPasta = $this->publicar($tenant, self::NUMERO_DA_PASTA, '91001',
            'DECISÃO Defiro a gratuidade provisoriamente. Intime-se a parte autora para, no prazo de 15 (quinze) dias, '
            . 'juntar declaração de hipossuficiência atualizada, sob pena de revogação. Cumpra-se.',
            $ontem, $processo);
        // Irmãs: MESMO escritório com número de outra pasta; OUTRO escritório com o MESMO número.
        $this->publicar($tenant, self::NUMERO_DE_OUTRA, '91002',
            'Intime-se a parte autora para, no prazo de 10 dias, juntar procuração atualizada.', $ontem);
        $this->publicar($outro, self::NUMERO_DA_PASTA, '91003',
            'Intime-se a parte autora para, no prazo de 10 dias, apresentar comprovante de residência.', $ontem);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, (int) $pasta->getId());

        $grupos = $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo');
        self::assertSame('juizo', $grupos->first()->attr('data-status'), 'exigido pelo juízo é o primeiro grupo (dc L4028)');

        $juizo = $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo[data-status="juizo"] > .ds-sug-item');
        self::assertSame(['hipossuf'], $juizo->each(static fn (Crawler $i): string => (string) $i->attr('data-chave')), 'só o da própria pasta e do próprio escritório');
        self::assertStringContainsString('Exigido pelo juízo', $crawler->filter('.ds-sug-grupo[data-status="juizo"] .ds-sug-grupo-nome')->text());

        $origem = $juizo->filter('.ds-sug-item > .ds-sug-item-texto > a.ds-sug-origem');
        self::assertSame(1, $origem->count());
        self::assertSame((string) $daPasta->getId(), $origem->attr('data-push-id'));
        self::assertSame('Origem: Decisão de ' . $ontem->format('d/m/Y') . ' (ID 491001)', trim($origem->text()));
        self::assertSame('/pasta/' . $pasta->getId() . '#push', $origem->attr('href'));
        self::assertSame(1, $crawler->filter('.ps-push-cab[aria-controls="push-teor-' . $daPasta->getId() . '"]')->count(), 'o alvo da origem existe no acordeão do Push');

        self::assertStringContainsString('prazo de 15 dias', $juizo->filter('.ds-sug-item-porque')->text());

        // Procuração está no catálogo da fase inicial, mas vem como OBRIGATÓRIO (lei), não do juízo:
        // a determinação que a pedia é de outra pasta.
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo[data-status="req"] > .ds-sug-item[data-chave="procuracao"]')->count());

        $prazos = $crawler->filter('#documentosSugeridosCorpo .ds-sug-contexto > #documentosSugeridosPrazos');
        self::assertSame(1, $prazos->count());
        self::assertStringContainsString('Prazos em curso: Manifestação: prazo de 15 dias (Decisão de ' . $ontem->format('d/m/Y') . ')', (string) preg_replace('/\s+/u', ' ', $prazos->text()));
        self::assertStringNotContainsString('prazo de 10 dias', $prazos->text(), 'prazo de publicação alheia não entra');

        self::assertSame('Confiança média', trim($crawler->filter('.ds-sug-conf')->text()));
        self::assertStringContainsString('Lido por regras o teor de 1 publicação do Push Processual', $crawler->filter('.ds-sug-motivo')->text());
    }

    #[TestDox('sem publicação (o filtro remove tudo): nenhum item do juízo, sem prazos, e a tela diz que o processo não foi lido')]
    public function testSemPublicacaoNadaDoJuizo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        [, $outro]       = $this->criarAdmin();

        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Indenização por danos morais');
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO_DA_PASTA));
        // Só existe a publicação do OUTRO escritório com o mesmo número.
        $this->publicar($outro, self::NUMERO_DA_PASTA, '92001',
            'Intime-se a parte autora para, no prazo de 15 dias, juntar declaração de hipossuficiência.', new \DateTimeImmutable('yesterday'));

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(0, $crawler->filter('#documentosSugeridosCorpo .ds-sug-grupo[data-status="juizo"]')->count());
        self::assertSame(0, $crawler->filter('#documentosSugeridosPrazos')->count());
        self::assertSame(0, $crawler->filter('a.ds-sug-origem')->count());
        self::assertSame('Confiança baixa', trim($crawler->filter('.ds-sug-conf')->text()));
        self::assertStringContainsString('nada aqui é exigência do juízo', $crawler->filter('.ds-sug-motivo')->text());
    }

    #[TestDox('prazo vencido não aparece em "Prazos em curso", mas o documento exigido continua')]
    public function testPrazoVencidoNaoAparece(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();

        $pasta = $this->criarPasta($tenant);
        $pasta->setNomeAcao('Indenização por danos morais');
        $processo = $this->criarProcesso($tenant, self::NUMERO_DA_PASTA);
        $this->vincular($pasta, $processo);
        $this->publicar($tenant, self::NUMERO_DA_PASTA, '93001',
            'Intime-se a parte autora para, no prazo de 5 dias, juntar declaração de hipossuficiência.',
            new \DateTimeImmutable('-60 days'), $processo);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(0, $crawler->filter('#documentosSugeridosPrazos')->count());
        self::assertSame(1, $crawler->filter('.ds-sug-grupo[data-status="juizo"] > .ds-sug-item[data-chave="hipossuf"]')->count());
    }
}
