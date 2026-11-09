<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * O que os funcionais da etapa 2 repetem, por cima de {@see NaTelaDeAssinatura}: montar e enviar um documento, tirar o
 * token do link do `Detalhe`, chamar as rotas públicas com o CSRF do link, ler o `Publico` da página, o código do
 * e-mail e os tipos da trilha.
 *
 * A classe que usa também usa `NaTelaDeAssinatura`.
 */
trait NaAssinaturaEnviada
{
    /** CPF válido de teste (dígitos verificadores certos). */
    private const CPF_ANA = '52998224725';

    /**
     * Um rascunho com Ana (com CPF) e Bia, as duas Simples e com e-mail.
     *
     * @param list<array<string, mixed>>|null $signatarios
     * @param array<string, mixed>            $extra
     */
    private function rascunho(?array $signatarios = null, bool $emOrdem = false, array $extra = []): int
    {
        $id = $this->salvar(null, array_merge([
            'nome' => 'Contrato de honorários',
            'mensagem' => 'Leia com atenção.',
            'emOrdem' => $emOrdem,
            'signatarios' => $signatarios ?? [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA, 'papel' => 'Contratante'],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com'],
            ],
            'blocos' => [['k' => 't', 'x' => 'Cláusula primeira.', 'full' => 0], ['k' => 'a', 'i' => 0, 'r' => 'Contratante'], ['k' => 'a', 'i' => 1, 'r' => '']],
        ], $extra));
        self::assertGreaterThan(0, $id, 'o rascunho foi salvo');

        return $id;
    }

    /** @return array<string, mixed> o `Detalhe` devolvido pelo envio */
    private function enviar(int $id): array
    {
        $this->client->request('POST', '/assinatura/documentos/' . $id . '/enviar', [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $json = $this->json();
        self::assertTrue($json['ok']);

        return $json['documento'];
    }

    /**
     * Os tokens dos links do `Detalhe`, na ordem dos signatários (nulo de quem não tem link).
     *
     * @param array<string, mixed> $detalhe
     *
     * @return list<?string>
     */
    private static function tokens(array $detalhe): array
    {
        return array_map(static function (array $s): ?string {
            if ($s['link'] === null) {
                return null;
            }
            self::assertSame(1, preg_match('#^https?://[^/]+/assinar/([a-f0-9]{64})$#', $s['link'], $m), $s['link']);

            return $m[1];
        }, $detalhe['signatarios']);
    }

    /**
     * Envia um rascunho novo e devolve o id e os tokens.
     *
     * @param list<array<string, mixed>>|null $signatarios
     *
     * @return array{int, list<?string>}
     */
    private function documentoEnviado(?array $signatarios = null, bool $emOrdem = false): array
    {
        $id = $this->rascunho($signatarios, $emOrdem);

        return [$id, self::tokens($this->enviar($id))];
    }

    /** @param array<string, mixed>|null $corpo */
    private function postarNoLink(string $url, string $token, ?array $corpo = null, ?string $csrf = null): void
    {
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrf ?? 'TOKEN_assinar_' . hash('sha256', $token),
        ], $corpo !== null ? json_encode($corpo, \JSON_THROW_ON_ERROR) : null);
    }

    private function pedirCodigo(string $token): string
    {
        $this->postarNoLink('/assinar/' . $token . '/codigo', $token);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        self::assertSame(['ok' => true], $this->json());

        return $this->codigoDoEmail();
    }

    /** O código de 6 dígitos do e-mail de código da última requisição. */
    private function codigoDoEmail(): string
    {
        self::assertEmailCount(1);
        $html = (string) self::getMailerMessage()?->getHtmlBody();
        self::assertSame(1, preg_match('#>(\d{6})</p>#', $html, $m), 'o código está no e-mail');

        return $m[1];
    }

    /** @param array<string, mixed> $troca */
    private function assinarNoLink(string $token, string $codigo, array $troca = []): void
    {
        $this->postarNoLink('/assinar/' . $token, $token, array_merge([
            'nome' => 'Ana Maria Lima',
            'cpf' => self::CPF_ANA,
            'codigo' => $codigo,
            'modo' => 'desenhada',
            'rubrica' => 'data:image/png;base64,' . self::PNG_1X1,
            'aceite' => true,
        ], $troca));
    }

    /** @return array<string, mixed> o `Publico` da página aberta pelo link */
    private function abrir(string $token, int $status = 200): array
    {
        $this->client->request('GET', '/assinar/' . $token);
        self::assertResponseStatusCodeSame($status);

        return $this->publicoDaPagina();
    }

    /** @return array<string, mixed> */
    private function publicoDaPagina(): array
    {
        $raiz = (new Crawler((string) $this->client->getResponse()->getContent()))->filter('body > #bjaPublica');
        self::assertCount(1, $raiz, 'a raiz #bjaPublica é filha direta do body');

        return json_decode((string) $raiz->attr('data-estado'), true, 64, \JSON_THROW_ON_ERROR);
    }

    /** O que o SQL do teste mudou passa a valer na próxima requisição (a de verdade começa sem nada em memória). */
    private function esquecer(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** A chave do Master ligada (sem linha em `recurso_estado`), para quem chama a rota pública sem `entrar()`. */
    private function chaveDoMasterLigada(): void
    {
        $this->conexao()->executeStatement('DELETE FROM recurso_estado WHERE chave = ?', ['assinatura']);
    }

    /** @return list<string> os tipos da trilha do documento, do mais antigo para o mais recente */
    private function trilha(int $id): array
    {
        return array_map('strval', $this->conexao()->fetchFirstColumn('SELECT tipo FROM assinatura_eletronica_evento WHERE documento_id = ? ORDER BY id', [$id]));
    }

    private static function assertCabecalhosPublicos(Response $resposta): void
    {
        self::assertSame('noindex, nofollow', $resposta->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
    }
}
