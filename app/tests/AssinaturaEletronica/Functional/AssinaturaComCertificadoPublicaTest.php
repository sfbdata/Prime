<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\AssinaturaPublicaController;
use App\AssinaturaEletronica\DTO\EntradaDoCertificado;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\UseCase\AssinarComCertificadoUseCase;
use App\AssinaturaEletronica\UseCase\SolicitarCodigoUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\AssinaturaEletronica\Support\ValidadorDeCadeiaQueResponde;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A assinatura com certificado pela HTTP (etapa 5, bloco 5C): o `POST /assinar/{token}` despacha pelo nível do
 * signatário. Um documento com o original já assinado (a versão base), Bia gov.br com CPF e Ana Simples, sem ordem,
 * enviado com o validador de cadeia dublado ({@see ValidadorDeCadeiaQueResponde}). Bia manda multipart (`nome`, `cpf`,
 * `aceite`, `arquivo`); Ana, o JSON da Simples, como antes. O banco é desfeito pelo DAMA.
 */
#[CoversClass(AssinaturaPublicaController::class)]
#[CoversClass(AssinarComCertificadoUseCase::class)]
#[CoversClass(EntradaDoCertificado::class)]
#[CoversClass(SolicitarCodigoUseCase::class)]
final class AssinaturaComCertificadoPublicaTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    /** A página do PDF e os lugares de assinatura de Bia e de Ana. */
    private const CONTEUDO = [['k' => 'pg', 'n' => 1], ['k' => 'a', 'i' => 0, 'r' => ''], ['k' => 'a', 'i' => 1, 'r' => '']];

    private ArmazenamentoEmMemoriaNoContainer $disco;
    private ValidadorDeCadeiaQueResponde $validador;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->validador = new ValidadorDeCadeiaQueResponde();
        static::getContainer()->set(HttpValidadorDeCadeia::class, $this->validador);
        $this->csrfPrevisivel();
    }

    #[TestDox('gov.br com a cadeia confirmada: 200 com o Publico, a versão devolvida com a cadeia, o arquivo guardado e "assinado" na trilha')]
    public function testAssinaComCertificado(): void
    {
        [$id, $bia, , $base] = $this->documento();
        $devolvido = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $this->assinarComCertificado($bia, $this->temporario('Contrato assinado.pdf', $devolvido));

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $json = $this->json();
        self::assertTrue($json['ok']);
        self::assertSame('ja_assinado', $json['estado']['estado']);
        self::assertCabecalhosPublicos($this->client->getResponse());
        $versoes = $this->versoes($id);
        self::assertSame([['original', 1, null], ['devolvida', 2, 'confirmada']], array_map(static fn (array $v): array => [$v['origem'], $v['ordem'], $v['cadeia_situacao']], $versoes));
        self::assertSame(hash('sha256', $devolvido), $versoes[1]['sha256']);
        self::assertSame('Contrato assinado.pdf', $versoes[1]['nome_original']);
        self::assertSame($devolvido, $this->disco->memoria->ler($this->disco->memoria->ultimaGravada()));
        self::assertSame(1, $this->validador->conferencias);

        $linha = $this->conexao()->fetchAssociative("SELECT situacao, metodo, nome_digitado, cpf_digitado, rubrica_chave FROM assinatura_eletronica_signatario WHERE documento_id = ? AND nivel = 'govbr'", [$id]);
        self::assertSame(['situacao' => 'assinado', 'metodo' => 'govbr', 'nome_digitado' => 'Bia Souza Lima', 'cpf_digitado' => OficinaDePdfAssinado::CPF, 'rubrica_chave' => null], $linha);
        self::assertSame('assinado', $this->trilha($id)[\count($this->trilha($id)) - 1]);
        $dados = json_decode((string) $this->conexao()->fetchOne("SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ? AND tipo = 'assinado'", [$id]), true);
        self::assertSame(['govbr', 'Contrato assinado.pdf', 2, hash('sha256', $devolvido), true], [$dados['metodo'], $dados['arquivo'], $dados['n'], $dados['sha256'], $dados['cpf_confere']]);
    }

    #[TestDox('recusa: 422 com o texto do catálogo; a definitiva vai para a trilha sem versão, a do validador fora do ar não grava nada')]
    public function testRecusa(): void
    {
        [$id, $bia, , $base] = $this->documento();
        $versoes = $this->versoes($id);
        $trilha = $this->trilha($id);

        $this->assinarComCertificado($bia, $this->temporario('outro.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec')));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Este arquivo não preserva as assinaturas anteriores. Assine o PDF baixado nesta tela, sem imprimir ou salvar como novo.'], $this->json());
        self::assertSame([...$trilha, 'pdf_assinado_recusado'], $this->trilha($id));
        self::assertSame(0, $this->validador->conferencias);

        $this->validador->troca = ['confiavel' => false];
        $this->assinarComCertificado($bia, $this->temporario('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O certificado desta assinatura não foi confirmado pela cadeia oficial.'], $this->json());
        self::assertSame([...$trilha, 'pdf_assinado_recusado', 'pdf_assinado_recusado'], $this->trilha($id));

        $this->validador->indisponivel = true;
        $this->assinarComCertificado($bia, $this->temporario('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Não foi possível conferir a assinatura agora. Tente de novo em alguns minutos.'], $this->json());
        self::assertSame([...$trilha, 'pdf_assinado_recusado', 'pdf_assinado_recusado'], $this->trilha($id), 'validador fora do ar não grava nada');

        self::assertSame($versoes, $this->versoes($id), 'nenhuma versão nova');
        self::assertSame('pendente', $this->conexao()->fetchOne("SELECT situacao FROM assinatura_eletronica_signatario WHERE documento_id = ? AND nivel = 'govbr'", [$id]));
        $dados = json_decode((string) $this->conexao()->fetchOne("SELECT dados FROM assinatura_eletronica_evento WHERE documento_id = ? AND tipo = 'pdf_assinado_recusado' ORDER BY id LIMIT 1", [$id]), true);
        self::assertSame(['prefixo', 'prefixo_nao_confere'], [$dados['motivo'], $dados['regra']]);
    }

    #[TestDox('o formulário vem antes do arquivo: sem nome "Informe o nome completo."; sem arquivo "Envie o PDF assinado."; sem aceite "Marque a confirmação de leitura."')]
    public function testFormulario(): void
    {
        [$id, $bia, , $base] = $this->documento();
        $trilha = $this->trilha($id);
        $pdf = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base);

        $this->assinarComCertificado($bia, $this->temporario('a.pdf', $pdf), ['nome' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe o nome completo.'], $this->json());

        $this->assinarComCertificado($bia, null);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Envie o PDF assinado.'], $this->json());

        $this->assinarComCertificado($bia, $this->temporario('a.pdf', $pdf), ['aceite' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Marque a confirmação de leitura.'], $this->json());

        self::assertSame($trilha, $this->trilha($id), 'a recusa do formulário não vai para a trilha');
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('a Simples continua pelo JSON: Ana pede o código e assina no mesmo documento')]
    public function testSimplesContinua(): void
    {
        [$id, , $ana] = $this->documento();

        $this->assinarNoLink($ana, $this->pedirCodigo($ana));

        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame('ja_assinado', $this->json()['estado']['estado']);
        self::assertSame('simples', $this->conexao()->fetchOne("SELECT metodo FROM assinatura_eletronica_signatario WHERE documento_id = ? AND nivel = 'simples'", [$id]));
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('pedir código como gov.br: 404 "Link inválido.", sem código, e-mail nem trilha')]
    public function testCodigoComoCertificado(): void
    {
        [$id, $bia] = $this->documento();
        $trilha = $this->trilha($id);

        $this->postarNoLink('/assinar/' . $bia . '/codigo', $bia);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['erro' => 'Link inválido.'], $this->json());
        self::assertEmailCount(0);
        self::assertSame($trilha, $this->trilha($id));
        self::assertNull($this->conexao()->fetchOne("SELECT codigo_hash FROM assinatura_eletronica_signatario WHERE documento_id = ? AND nivel = 'govbr'", [$id]));
    }

    /**
     * Rascunho com o original já assinado (a versão base) e Bia gov.br com CPF e Ana Simples, enviado. Sai do escritório
     * antes de devolver: a tela pública é sem login.
     *
     * @return array{int, string, string, string} o id, o token de Bia, o token de Ana e os bytes da base
     */
    private function documento(): array
    {
        $this->entrar(true);
        $base = OficinaDePdfAssinado::pdfAssinado();
        $id = $this->salvar(null, [
            'nome' => 'Contrato',
            'signatarios' => [
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => OficinaDePdfAssinado::CPF],
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'],
            ],
            'blocos' => self::CONTEUDO,
        ], $this->temporario('contrato.pdf', $base));
        self::assertResponseStatusCodeSame(201);
        [$bia, $ana] = self::tokens($this->enviar($id));
        $this->sair($this->client);

        return [$id, (string) $bia, (string) $ana, $base];
    }

    /** @param array<string, string> $troca */
    private function assinarComCertificado(string $token, ?UploadedFile $arquivo, array $troca = []): void
    {
        $campos = $troca + ['nome' => 'Bia Souza Lima', 'cpf' => '529.982.247-25', 'aceite' => '1'];
        $this->client->request('POST', '/assinar/' . $token, $campos, array_filter(['arquivo' => $arquivo]), [
            'HTTP_X_CSRF_TOKEN' => 'TOKEN_assinar_' . hash('sha256', $token),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function versoes(int $documento): array
    {
        return $this->conexao()->fetchAllAssociative('SELECT origem, ordem, nome_original, sha256, cadeia_situacao FROM assinatura_eletronica_versao WHERE documento_id = ? ORDER BY ordem', [$documento]);
    }
}
