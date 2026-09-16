<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A remoção física de arquivo tem UM caminho, e ele fica depois da transação (E2.5, INV-6).
 *
 * Antes da E2.5 havia 19 `excluir()` espalhados, nove deles antes do COMMIT: um `flush` recusado
 * deixava registro apontando para arquivo apagado. Agora quem apaga arquivo pede à
 * `RemocaoAposTransacao` (arquivos cuja linha saiu) ou à `TransacaoComArquivoNovo` (arquivo novo de
 * transação comprovadamente não confirmada), e a ordem vira coisa que se revisa num lugar só.
 *
 * O que isto trava — e o que não:
 *
 *  - ninguém chama `->excluir(` fora da remoção (regex não sabe o tipo: a exceção de outro domínio
 *    fica listada, com o motivo);
 *  - ninguém mais chama o `excluir()` do storage antigo, nem por outro nome de propriedade;
 *  - `->excluirPrefixo(` e o tipo `ArmazenamentoComPrefixo` têm um único consumidor, a purga (§3.3);
 *  - a consulta do destino da transação só é usada pela transação e pela purga.
 *
 * Não prova que a remoção é chamada DEPOIS do `flush` em cada ponto, nem impede chamar a remoção
 * num `catch` que envolva o COMMIT — isso é dos testes de cada ponto, que injetam a falha do banco
 * (e, nos quatro cleanups, a do próprio COMMIT) e conferem que o arquivo ficou.
 */
final class ExclusaoAposTransacaoArquiteturaTest extends TestCase
{
    /**
     * Quem pode escrever `->excluir(`:
     *
     *  - `RemocaoAposTransacao` — é o caminho da remoção física;
     *  - `NotificacaoController` — `NotificacaoService::excluir()` apaga notificações, não arquivos.
     */
    private const QUEM_CHAMA_EXCLUIR = [
        'src/Controller/NotificacaoController.php',
        'src/Shared/Armazenamento/RemocaoAposTransacao.php',
    ];

    #[TestDox('só a remoção pós-transação chama excluir() — o resto pede a ela')]
    public function testSoARemocaoChamaExcluir(): void
    {
        self::assertSame(self::QUEM_CHAMA_EXCLUIR, $this->arquivosQueCasam('/->\s*excluir\s*\(/'));
    }

    #[TestDox('ninguém usa o excluir() do storage antigo')]
    public function testNinguemUsaOExcluirAntigo(): void
    {
        // A assinatura antiga recebia caminho: `excluir($this->storage->caminho(...))` ou
        // `excluir($caminho)`. Qualquer `caminho(` dentro de um `excluir(` é o padrão velho.
        self::assertSame([], $this->arquivosQueCasam('/excluir\s*\([^;]*->caminho\s*\(/'));
        self::assertSame([], $this->arquivosQueCasam('/(storage|Storage)\s*->\s*excluir\s*\(/'));
    }

    #[TestDox('excluirPrefixo() e ArmazenamentoComPrefixo têm um único consumidor fora do núcleo: a purga')]
    public function testPrefixoTemUmUnicoConsumidor(): void
    {
        $foraDoNucleo = static fn (array $arquivos): array => array_values(array_filter(
            $arquivos,
            static fn (string $a): bool => !str_starts_with($a, 'src/Shared/Armazenamento/'),
        ));

        self::assertSame(
            ['src/Tenant/UseCase/PurgarEscritorioUseCase.php'],
            $foraDoNucleo($this->arquivosQueCasam('/->\s*excluirPrefixo\s*\(|\bArmazenamentoComPrefixo\b/')),
            'Apagar um escritório inteiro por prefixo só faz sentido na purga (D7, §3.3).',
        );
    }

    /**
     * Além da própria transação, só a purga pergunta o destino: ela faz o COMMIT por DBAL (não por
     * `flush`) e é o ponto mais destrutivo — um COMMIT incerto ali não pode virar "nada foi apagado".
     */
    #[TestDox('a consulta do destino da transação só é usada pela transação e pela purga')]
    public function testConsultaDoDestinoFicaNaTransacao(): void
    {
        self::assertSame(
            [
                'src/Shared/Doctrine/Transacao/ConsultaDeDestinoDaTransacao.php',
                'src/Shared/Doctrine/Transacao/ConsultaDeDestinoNoPostgres.php',
                'src/Shared/Doctrine/Transacao/TransacaoComArquivoNovo.php',
                'src/Tenant/UseCase/PurgarEscritorioUseCase.php',
            ],
            $this->arquivosQueCasam('/\bConsultaDeDestino(DaTransacao|NoPostgres)\b|pg_xact_status/'),
        );
    }

    /** @return list<string> */
    private function arquivosQueCasam(string $padrao): array
    {
        $raiz        = \dirname(__DIR__, 2);
        $encontrados = [];

        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || $arquivo->getExtension() !== 'php') {
                continue;
            }

            if (preg_match($padrao, $this->semComentarios((string) file_get_contents($arquivo->getPathname()))) === 1) {
                $encontrados[] = str_replace($raiz . '/', '', $arquivo->getPathname());
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    private function semComentarios(string $codigo): string
    {
        $limpo = '';

        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token)) {
                if ($token[0] !== \T_COMMENT && $token[0] !== \T_DOC_COMMENT) {
                    $limpo .= $token[1];
                }

                continue;
            }

            $limpo .= $token;
        }

        return $limpo;
    }
}
