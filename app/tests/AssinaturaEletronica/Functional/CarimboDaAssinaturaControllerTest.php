<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Controller\CarimboDaAssinaturaController;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\UseCase\ObterCopiaComCarimboUseCase;
use App\Entity\Tenant\Tenant;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A rota da "Cópia com carimbo visível" (etapa 5, bloco 5B) pela HTTP: o PDF carimbado como anexo, a recusa em 422
 * com o texto do desenho, 404 para documento de outro escritório ou sem PDF assinado guardado, 403 sem a Assinatura, e
 * nada gravado. O armazenamento é o dublê em memória: a chave lida tem de ser a do escritório do documento (R1).
 */
#[CoversClass(CarimboDaAssinaturaController::class)]
#[CoversClass(ObterCopiaComCarimboUseCase::class)]
final class CarimboDaAssinaturaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    private const CPF_ANA = '52998224725';

    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
    }

    #[TestDox('200: o PDF guardado com o carimbo acrescentado no fim, como anexo "{nome} - com carimbo.pdf" e sem cache')]
    public function testCopiaCarimbada(): void
    {
        [, $tenant] = $this->entrar(true);
        $original = OficinaDePdfAssinado::pdfAssinado();
        $documento = $this->documento($tenant, 'Contrato 01/2026 de honorários', $original);

        $this->client->request('GET', '/assinatura/documentos/' . $documento->getId() . '/carimbo');

        self::assertResponseStatusCodeSame(200);
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertSame(
            'attachment; filename="Contrato 01-2026 de honor_rios - com carimbo.pdf"; filename*=utf-8\'\'Contrato%2001-2026%20de%20honor%C3%A1rios%20-%20com%20carimbo.pdf',
            $resposta->headers->get('Content-Disposition'),
            'a barra do nome vira "-" e o fallback ASCII troca o "á"',
        );
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $corpo = (string) $resposta->getContent();
        self::assertStringStartsWith($original, $corpo, 'acréscimo incremental: o original fica intacto no começo');
        $acrescimo = substr($corpo, \strlen($original));
        self::assertStringContainsString('/Name/BlueJus', $acrescimo);
        self::assertStringContainsString((string) $documento->getCodigo(), $acrescimo);
    }

    #[TestDox('404 comum: documento de outro escritório ou inexistente, sem ler arquivo')]
    public function testNaoEncontrado(): void
    {
        $this->entrar(true);
        $alheio = $this->documento($this->outroEscritorio(), 'Contrato alheio', OficinaDePdfAssinado::pdfAssinado());

        foreach ([$alheio->getId(), 999999999] as $id) {
            $this->client->request('GET', '/assinatura/documentos/' . $id . '/carimbo');
            self::assertResponseStatusCodeSame(404, 'documento ' . $id);
            self::assertStringNotContainsString('Original não encontrado', (string) $this->client->getResponse()->getContent(), 'o 404 comum não diz se o documento existe');
        }
        self::assertSame([], $this->disco->memoria->lidas, 'nenhum arquivo é lido');
    }

    #[TestDox('404 "Original não encontrado.": documento sem PDF assinado guardado ou com o arquivo ausente')]
    public function testSemOriginal(): void
    {
        [, $tenant] = $this->entrar(true);
        $semVersao = $this->documento($tenant, 'Contrato sem original', null);
        $semArquivo = $this->documento($tenant, 'Contrato sem arquivo', OficinaDePdfAssinado::pdfAssinado(), comArquivo: false);

        foreach ([$semVersao->getId(), $semArquivo->getId()] as $id) {
            $this->client->request('GET', '/assinatura/documentos/' . $id . '/carimbo');
            self::assertResponseStatusCodeSame(404, 'documento ' . $id);
            self::assertSame(['erro' => 'Original não encontrado.'], $this->json());
        }
    }

    #[TestDox('403: usuário sem acesso à Assinatura, mesmo com o documento do próprio escritório')]
    public function testSemAcesso(): void
    {
        [, $tenant] = $this->entrar(false);
        $documento = $this->documento($tenant, 'Contrato', OficinaDePdfAssinado::pdfAssinado());

        $this->client->request('GET', '/assinatura/documentos/' . $documento->getId() . '/carimbo');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->disco->memoria->lidas);
    }

    #[TestDox('422 sem nenhuma assinatura eletrônica do BlueJus: o texto do desenho e a marca semAssinatura, sem ler o arquivo')]
    public function testSemAssinatura(): void
    {
        [, $tenant] = $this->entrar(true);
        $documento = $this->documento($tenant, 'Contrato', OficinaDePdfAssinado::pdfAssinado(), anaAssina: false);

        $this->client->request('GET', '/assinatura/documentos/' . $documento->getId() . '/carimbo');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar.', 'semAssinatura' => true], $this->json());
        self::assertSame([], $this->disco->memoria->lidas);
    }

    #[TestDox('422 recusa do carimbo, e nada é gravado: o documento, as versões, a trilha e o armazenamento ficam iguais')]
    public function testNadaGravado(): void
    {
        [, $tenant] = $this->entrar(true);
        $carimbado = $this->documento($tenant, 'Contrato', OficinaDePdfAssinado::pdfAssinado());
        $recusado = $this->documento($tenant, 'Contrato cifrado', OficinaDePdfAssinado::pdfSemAssinatura('/Encrypt 9 0 R'));
        $antes = $this->retrato([(int) $carimbado->getId(), (int) $recusado->getId()]);

        $this->client->request('GET', '/assinatura/documentos/' . $carimbado->getId() . '/carimbo');
        self::assertResponseStatusCodeSame(200);
        $this->client->request('GET', '/assinatura/documentos/' . $recusado->getId() . '/carimbo');
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Arquivo protegido por senha.'], $this->json(), 'só o motivo, sem semAssinatura: o " Use o original." é da tela');

        self::assertSame($antes, $this->retrato([(int) $carimbado->getId(), (int) $recusado->getId()]));
        self::assertSame([], $this->disco->memoria->gravadas, 'nenhum arquivo novo');
        self::assertSame([], $this->disco->memoria->excluidas, 'nenhum arquivo apagado');
    }

    /**
     * Um documento enviado com Ana e Bia (Simples), o original guardado (se houver PDF; o arquivo só com `$comArquivo`) e
     * a assinatura da Ana, montado direto pelas entidades: o salvar do rascunho que guarda o original é do bloco 5A.
     */
    private function documento(Tenant $tenant, string $nome, ?string $pdf, bool $anaAssina = true, bool $comArquivo = true): DocumentoParaAssinar
    {
        $agora = new \DateTimeImmutable();
        // O `clear()` do documento anterior soltou o escritório: o documento novo leva o da base.
        $tenant = static::getContainer()->get(EntityManagerInterface::class)->find(Tenant::class, $tenant->getId()) ?? self::fail('escritório sumiu');
        $documento = new DocumentoParaAssinar($tenant, null, $agora);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => $nome,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com'],
            ],
        ]), [], $agora);
        $documentos = static::getContainer()->get(DocumentoParaAssinarRepository::class);
        $documentos->salvar($documento);
        if ($pdf !== null) {
            $versao = VersaoDoPdf::original($documento, 1, bin2hex(random_bytes(16)) . '.pdf', $nome . '.pdf', hash('sha256', $pdf), \strlen($pdf), ['v' => 1], $agora);
            if ($comArquivo) {
                $this->disco->memoria->semear(ChavesDeAssinaturaEletronica::versao($versao), $pdf);
            }
            static::getContainer()->get(VersaoDoPdfRepository::class)->salvar($versao);
        }
        $documento->enviar(hash('sha256', $nome . uniqid()), $agora);
        $documento->definirCodigo(strtoupper(\sprintf('BJA-%s-%s-%s', bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)))));
        if ($anaAssina) {
            $documento->assinar($documento->getSignatarios()[0], 'Ana Maria Lima', self::CPF_ANA, 'rubrica.png', 'Assinatura desenhada', null, null, $agora);
        }
        $documentos->salvar($documento);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return $documento;
    }

    private function outroEscritorio(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Outro escritório ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    /**
     * As linhas do documento, dos signatários, das versões e da trilha.
     *
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function retrato(array $ids): array
    {
        $lista = implode(',', $ids);

        return [
            'documentos' => $this->conexao()->fetchAllAssociative('SELECT * FROM assinatura_eletronica_documento WHERE id IN (' . $lista . ') ORDER BY id'),
            'signatarios' => $this->conexao()->fetchAllAssociative('SELECT * FROM assinatura_eletronica_signatario WHERE documento_id IN (' . $lista . ') ORDER BY id'),
            'versoes' => $this->conexao()->fetchAllAssociative('SELECT * FROM assinatura_eletronica_versao WHERE documento_id IN (' . $lista . ') ORDER BY id'),
            'eventos' => (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM assinatura_eletronica_evento WHERE documento_id IN (' . $lista . ')'),
        ];
    }
}
