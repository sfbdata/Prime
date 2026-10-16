<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\AndamentoDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Faixa "Avançar para X?" do andamento (desenho 1.2.3, `faseSugestao`): o Push lê também o tipo do documento da
 * publicação, e documentos anexados sugerem "Documentação".
 */
#[CoversClass(AndamentoDaPasta::class)]
final class SugestoesDoAndamentoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO = '07011345720258070007';

    #[TestDox('intimação cujo documento é "Sentença" sugere Sentença com "Push: sentença em dd/mm/aaaa"')]
    public function testTipoDoDocumentoNoPush(): void
    {
        static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $pub = $this->criarPublicacao($tenant, (string) random_int(100000000, 999999999), self::NUMERO, '2026-10-01', $processo);
        $pub->setTipoDocumento('Sentença');
        $this->em()->flush();

        $sug = $this->porNome($pasta);

        self::assertArrayHasKey('Sentença', $sug);
        self::assertSame('Push: sentença em 01/10/2026', $sug['Sentença']['motivo']);
    }

    #[TestDox('intimação sem tipo de documento e sem "sentença" no teor não sugere Sentença')]
    public function testIntimacaoSemTipoNaoSugere(): void
    {
        static::createClient();
        [, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $this->criarPublicacao($tenant, (string) random_int(100000000, 999999999), self::NUMERO, '2026-10-01', $processo);

        self::assertArrayNotHasKey('Sentença', $this->porNome($pasta));
    }

    #[TestDox('documento vivo na aba Documentos sugere "Documentação"; o da lixeira não conta')]
    public function testDocumentacao(): void
    {
        static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $lixo = $this->documento($pasta, 'lixo.pdf');
        $lixo->marcarExcluido($user, new \DateTimeImmutable());
        $this->em()->flush();
        self::assertArrayNotHasKey('Documentação', $this->porNome($pasta));

        $this->documento($pasta, 'rg.pdf');
        $this->em()->flush();

        $sug = $this->porNome($pasta);
        self::assertArrayHasKey('Documentação', $sug);
        self::assertSame('1 documento(s) anexado(s) na aba Documentos', $sug['Documentação']['motivo']);
    }

    /** @return array<string, array{nome: string, indice: int, motivo: string, icone: string}> */
    private function porNome(Pasta $pasta): array
    {
        $out = [];
        foreach (static::getContainer()->get(AndamentoDaPasta::class)->sugestoes($pasta, $pasta->getTenant()) as $s) {
            $out[$s['nome']] = $s;
        }

        return $out;
    }

    private function documento(Pasta $pasta, string $nome): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTitulo($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $doc->setCaminhoArquivo('uploads/fake/' . $nome);
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(1024);
        $doc->setPasta($pasta);
        $doc->setTenant($pasta->getTenant());
        $pasta->addDocumento($doc);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }
}
