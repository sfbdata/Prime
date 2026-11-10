<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\AssinaturaEletronica\Service\CofreDaSelfie;
use App\AssinaturaEletronica\Service\ConferenciaDoEnvio;
use App\AssinaturaEletronica\Service\PaginaDoSignatario;
use App\AssinaturaEletronica\UseCase\AssinarDocumentoUseCase;
use App\AssinaturaEletronica\UseCase\EnviarDocumentoUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O nível Reforçada pela HTTP (contrato da etapa 7): o envio (aceito, menor, sem chave), a tela pública (selfie cifrada
 * no disco, localização nas colunas, trilha e detalhe) e as recusas do "Assinar" (sem selfie, sem localização, imagem
 * inválida, grande ou pequena, sem chave), sempre sem gravar nada quando recusa.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(AssinarDocumentoUseCase::class)]
#[CoversClass(EnviarDocumentoUseCase::class)]
#[CoversClass(ConferenciaDoEnvio::class)]
#[CoversClass(PaginaDoSignatario::class)]
#[CoversClass(ApresentacaoDoDocumento::class)]
final class AssinaturaReforcadaTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private const LOCAL = ['latitude' => -15.7938806, 'longitude' => -47.8827763, 'precisao' => 25];

    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
    }

    #[TestDox('Reforçada completa: selfie cifrada no disco, colunas, evidências na trilha, detalhe e o texto da trilha')]
    public function testReforcadaCompleta(): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado(self::signatarios());
        self::assertTrue($this->abrir((string) $tokens[0])['reforcada']);
        self::assertFalse($this->abrir((string) $tokens[1])['reforcada'], 'Bia é Simples');

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]), self::reforco());

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('ja_assinado', $this->json()['estado']['estado']);
        self::assertStringNotContainsString(base64_encode(self::jpeg(320, 240)), (string) $this->client->getResponse()->getContent());

        $linha = $this->linhaDaAna($id);
        self::assertSame(['reforcada', '-15.79388060', '-47.88277630', '25.00', 1], [$linha['metodo'], $linha['latitude'], $linha['longitude'], $linha['precisao'], (int) $linha['selfie_versao']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.cifrado$/', (string) $linha['selfie_chave']);
        $arquivo = $this->disco->ler(new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $linha['tenant_id']), CategoriaDeArquivo::ASSINATURA_DOCUMENTO, (string) $linha['selfie_chave']));
        self::assertStringStartsWith('BJSELFIE', $arquivo);

        $dados = json_decode((string) $this->conexao()->fetchOne("SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ? AND tipo = 'assinado'", [$id]), true);
        $chaves = array_values(array_diff(array_keys($dados), ['porta']));
        sort($chaves);
        self::assertSame(['aceite', 'chave', 'cpf_confere', 'latitude', 'longitude', 'metodo', 'modo', 'precisao', 'rubrica_sha256', 'selfie_sha256', 'selfie_versao'], $chaves);
        self::assertSame([$linha['selfie_sha256'], 1, 'reforcada'], [$dados['selfie_sha256'], $dados['selfie_versao'], $dados['metodo']]);

        $detalhe = $this->detalhe($id);
        [$ana, $bia] = $detalhe['signatarios'];
        self::assertTrue($ana['selfie']);
        self::assertSame(['latitude' => '-15.79388060', 'longitude' => '-47.88277630', 'precisao' => '25.00'], $ana['localizacao']);
        self::assertSame('reforcada', $ana['metodo']);
        self::assertFalse($bia['selfie']);
        self::assertNull($bia['localizacao']);
        self::assertContains('Assinado por Ana Maria Lima · E-mail e código, selfie, localização -15.79388, -47.88278', array_column($detalhe['eventos'], 'texto'));
        self::assertStringNotContainsString('cifrado', (string) json_encode($detalhe), 'o detalhe não diz onde a selfie está');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function assinaturasRecusadas(): iterable
    {
        yield 'sem selfie' => [['selfie' => ''], 'Envie a selfie com o documento.'];
        yield 'sem localização' => [['localizacao' => null], 'Permita o acesso à localização para assinar.'];
        yield 'imagem que não é JPEG nem PNG' => [['selfie' => 'data:image/jpeg;base64,' . base64_encode('<svg></svg>')], 'A selfie não pôde ser lida. Envie uma foto JPEG ou PNG.'];
        yield 'imagem acima de 4 MB' => [['selfie' => 'data:image/jpeg;base64,' . str_repeat('A', 5_600_000)], 'A selfie pode ter até 4 MB. Tire a foto de novo.'];
        yield 'imagem pequena demais' => [['selfie' => 'data:image/jpeg;base64,' . base64_encode(self::jpeg(120, 90))], 'O tamanho da foto não serve. Tire a selfie de novo.'];
        yield 'localização fora da faixa' => [['localizacao' => ['latitude' => 91, 'longitude' => 0, 'precisao' => 1]], 'A localização recebida não é válida. Recarregue a página e tente de novo.'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('assinaturasRecusadas')]
    #[TestDox('o "Assinar" Reforçado recusa ($_dataName) com 422 e nada gravado')]
    public function testAssinarRecusado(array $troca, string $erro): void
    {
        $this->entrar(true);
        [$id, $tokens] = $this->documentoEnviado(self::signatarios());

        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]), array_merge(self::reforco(), $troca));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $erro], $this->json());
        $linha = $this->linhaDaAna($id);
        self::assertSame(['pendente', null, null], [$linha['situacao'], $linha['selfie_chave'], $linha['latitude']]);
        self::assertSame([], $this->disco->memoria->gravadas);
        self::assertNotContains('assinado', $this->trilha($id));
    }

    /** @return iterable<string, array{string}> */
    public static function papeisDeMenor(): iterable
    {
        yield 'Menor assistido(a)' => ['Menor assistido(a)'];
        yield 'Menor Representado ou Assistido' => ['Menor Representado ou Assistido'];
    }

    #[DataProvider('papeisDeMenor')]
    #[TestDox('envio com Reforçada para o papel "$_dataName": 422 com o nome e o documento continua rascunho')]
    public function testEnvioDeMenor(string $papel): void
    {
        $this->entrar(true);
        $id = $this->rascunho([
            ['nome' => 'Rui Lima', 'email' => 'rui@exemplo.com', 'papel' => 'Responsável legal'],
            ['nome' => 'Lia Lima', 'email' => 'lia@exemplo.com', 'papel' => $papel, 'nivel' => 'reforcada'],
        ]);

        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A assinatura Reforçada colhe selfie e não é usada para menor (Lia Lima). Escolha outra forma de assinatura.', $this->json()['erro']);
        self::assertSame('rascunho', $this->detalhe($id)['situacao']);
        self::assertEmailCount(0);
    }

    #[TestDox('sem a chave da selfie: o envio com Reforçada recusa; o "Assinar" Reforçado também, sem gravar nada')]
    public function testSemChave(): void
    {
        static::getContainer()->set(CofreDaSelfie::class, new CofreDaSelfie(''));
        $this->entrar(true);
        $reforcado = $this->rascunho(self::signatarios());
        $this->client->request('POST', '/assinatura/documentos/' . $reforcado . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Assinatura reforçada sem chave configurada. Fale com o suporte.', $this->json()['erro']);
        self::assertSame('rascunho', $this->detalhe($reforcado)['situacao']);

        // Chave tirada depois do envio (I7): o documento saiu Simples e o nível vira Reforçada no banco.
        [$id, $tokens] = $this->documentoEnviado();
        $this->conexao()->executeStatement("UPDATE assinatura_eletronica_signatario SET nivel = 'reforcada' WHERE documento_id = ? AND ordem = 0", [$id]);
        $this->esquecer();
        $this->assinarNoLink((string) $tokens[0], $this->pedirCodigo((string) $tokens[0]), self::reforco());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Assinatura reforçada sem chave configurada. Fale com o suporte.'], $this->json());
        self::assertSame('pendente', $this->linhaDaAna($id)['situacao']);
        self::assertSame([], $this->disco->memoria->gravadas);
    }

    #[TestDox('prévia do escritório de quem é Reforçada: "reforcada" no Publico, só de leitura')]
    public function testPrevia(): void
    {
        $this->entrar(true);
        $id = $this->rascunho(self::signatarios());

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/previa/0');
        self::assertResponseIsSuccessful();
        $publico = $this->publicoDaPagina();
        self::assertTrue($publico['previa']);
        self::assertTrue($publico['reforcada']);
        self::assertSame(['nome', 'cpf', 'posicao'], array_keys($publico['eu']), 'nem papel nem nível no eu');

        $this->client->request('GET', '/assinatura/documentos/' . $id . '/previa/1');
        self::assertFalse($this->publicoDaPagina()['reforcada']);
    }

    /** @return list<array<string, mixed>> Ana Reforçada (com CPF) e Bia Simples */
    private static function signatarios(): array
    {
        return [
            ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA, 'papel' => 'Contratante', 'nivel' => 'reforcada'],
            ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com'],
        ];
    }

    /** @return array<string, mixed> o que a tela manda a mais na Reforçada */
    private static function reforco(): array
    {
        return ['selfie' => 'data:image/jpeg;base64,' . base64_encode(self::jpeg(320, 240)), 'localizacao' => self::LOCAL];
    }

    private static function jpeg(int $largura, int $altura): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagefilledrectangle($imagem, 0, 0, $largura - 1, $altura - 1, (int) imagecolorallocate($imagem, 200, 120, 40));
        ob_start();
        imagejpeg($imagem, null, 90);

        return (string) ob_get_clean();
    }

    /** @return array<string, mixed> a linha da Ana (ordem 0) com o escritório do documento */
    private function linhaDaAna(int $id): array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT s.situacao, s.metodo, s.selfie_chave, s.selfie_sha256, s.selfie_versao, s.latitude, s.longitude, s.precisao, d.tenant_id FROM assinatura_eletronica_signatario s JOIN assinatura_eletronica_documento d ON d.id = s.documento_id WHERE d.id = ? AND s.ordem = 0',
            [$id],
        );
        self::assertIsArray($linha);

        return $linha;
    }
}
