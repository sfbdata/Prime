<?php

declare(strict_types=1);

namespace App\Tests\Shared\Paralelo;

/**
 * Isola cada processo do ParaTest: banco, uploads, logs e temporários próprios.
 *
 * O ParaTest numera os processos em `TEST_TOKEN` (1..N) e marca `PARATEST=1`. Sozinho, esse número
 * colidiria entre frentes (todas teriam `saas_test1`), por isso o script de paralelo exporta o nome
 * da frente em `TEST_FRENTE` e aqui os dois viram um token só: `<frente>_P<n>`. O `P` é maiúsculo
 * de propósito: nome de frente só tem minúsculas (scripts/frente-abrir.sh), então o banco de um
 * processo nunca coincide com o banco serial de outra frente (a frente `a_p1` existe; `a_P1` não). O `TEST_TOKEN` é
 * reescrito (e não substituído por outra variável) porque há quem o leia direto, além do
 * doctrine.yaml (`tests/Mcp/BancoDeLeituraDeTeste.php`).
 *
 * `TESTE_PROCESSO` (`-p<n>`) é o sufixo dos diretórios de upload e dos logs (services.yaml e
 * monolog.yaml, no when@test). Os temporários vão para um TMPDIR próprio: o diretório privado do
 * Ghostscript, do zip e do export, as vagas de zip e os locks de comando moram em
 * `sys_get_temp_dir()`, que é um só por usuário. No nome do temporário a `/` da frente vira `+`, que
 * nenhuma frente contém (`eng/cache` e `eng-cache` não dividem diretório).
 *
 * Fora do ParaTest a execução serial continua com o `TEST_TOKEN` de fora (o da frente, ou nenhum) e
 * sem sufixo. Nas duas, o bootstrap garante que os diretórios de upload do processo existam: há
 * testes que gravam direto neles, e um diretório de processo recém-criado (ou um checkout novo, sem
 * `var/`) os derrubava.
 */
final class ProcessoDeTesteParalelo
{
    /**
     * Os diretórios de `var/uploads-test<sufixo>/` que o when@test do services.yaml usa. O
     * ProcessoDeTesteParaleloTest confere esta lista contra o yaml.
     */
    public const DIRETORIOS_DE_UPLOAD = [
        'chamados',
        'clientes',
        'cobrancas',
        'comercial-imagens',
        'documentos-juridicos',
        'justificativas',
        'kanban',
        'pastas',
        'perfil',
        'recompensa-comprovantes',
        'tarefas',
        'trabalho-externo',
    ];

    /**
     * Arquivo que mantém cada diretório de upload não vazio. Há teste que, ao limpar o que criou, faz
     * `rmdir` da pasta do arquivo (PecaImagemControllerTest); se o arquivo estava na raiz e ela ficou
     * vazia, a raiz sumia e o próximo teste do processo falhava. As listagens dos testes comparam o
     * antes com o depois, e a sentinela está nos dois. Em `cobrancas` ela não dura: os tearDown de
     * Cobrança esvaziam o diretório inteiro, mas não apagam a raiz, e ninguém faz `rmdir` dela.
     */
    public const SENTINELA = '.mantido-pelo-bootstrap-de-teste';

    /**
     * As variáveis que o processo precisa, ou nenhuma fora do ParaTest.
     *
     * @param array<string, string> $ambiente
     *
     * @return array<string, string>
     */
    public static function variaveis(array $ambiente, string $raizTemporaria): array
    {
        if (($ambiente['PARATEST'] ?? '') === '') {
            return [];
        }

        $numero = $ambiente['TEST_TOKEN'] ?? '';
        if (preg_match('/^[1-9][0-9]*$/', $numero) !== 1) {
            throw new \RuntimeException(sprintf('TEST_TOKEN do ParaTest inválido: "%s".', $numero));
        }

        // Mesma regra de nome do scripts/frente-abrir.sh: só minúsculas, por isso o "_P" do token não
        // aparece em nenhuma frente.
        $frente = $ambiente['TEST_FRENTE'] ?? '';
        if ($frente !== '' && preg_match('#^[a-z0-9][a-z0-9._/-]*$#', $frente) !== 1) {
            throw new \RuntimeException(sprintf('TEST_FRENTE inválida: "%s".', $frente));
        }

        $token      = $frente . '_P' . $numero;
        $temporario = rtrim($raizTemporaria, '/') . '/jusprime-teste-' . str_replace('/', '+', $token);

        return [
            'TEST_TOKEN'     => $token,
            'TESTE_PROCESSO' => '-p' . $numero,
            'TMPDIR'         => $temporario,
            'TMP'            => $temporario,
            'TEMP'           => $temporario,
        ];
    }

    /** Chamado pelo tests/bootstrap.php, antes do Dotenv e de qualquer `sys_get_temp_dir()`. */
    public static function aplicar(string $raizDoProjeto): void
    {
        $ambiente = getenv();
        $variaveis = self::variaveis($ambiente, $ambiente['TMPDIR'] ?? '/tmp');
        if ($variaveis !== []) {
            self::isolar($variaveis);
        }

        self::prepararUploads($raizDoProjeto . '/var/uploads-test' . (string) getenv('TESTE_PROCESSO'));
    }

    public static function prepararUploads(string $raiz): void
    {
        foreach (self::DIRETORIOS_DE_UPLOAD as $nome) {
            $diretorio = $raiz . '/' . $nome;
            if (!is_dir($diretorio) && !@mkdir($diretorio, 0o755, true) && !is_dir($diretorio)) {
                throw new \RuntimeException(sprintf('Não foi possível criar o diretório de upload de teste %s.', $diretorio));
            }

            $sentinela = $diretorio . '/' . self::SENTINELA;
            if (!is_file($sentinela) && @file_put_contents($sentinela, '') === false) {
                throw new \RuntimeException(sprintf(
                    'Não foi possível gravar em %s. Se o dono for outro usuário: docker exec -u 0 jusprime_php_dev chown -R 1000:1000 %s',
                    $diretorio,
                    $raiz,
                ));
            }
        }
    }

    /** @param array<string, string> $variaveis */
    private static function isolar(array $variaveis): void
    {
        $temporario = $variaveis['TMPDIR'];
        if (!is_dir($temporario) && !@mkdir($temporario, 0o700) && !is_dir($temporario)) {
            throw new \RuntimeException(sprintf('Não foi possível criar o temporário do processo %s.', $temporario));
        }
        @chmod($temporario, 0o700);

        foreach ($variaveis as $nome => $valor) {
            putenv($nome . '=' . $valor);
            $_ENV[$nome]    = $valor;
            $_SERVER[$nome] = $valor;
        }

        // O PHP guarda o temporário na primeira chamada: se alguém chamou antes, o TMPDIR não pegou
        // e os processos voltariam a dividir o mesmo diretório sem ninguém perceber.
        if (sys_get_temp_dir() !== $temporario) {
            throw new \RuntimeException(sprintf(
                'O temporário do processo não pegou: sys_get_temp_dir() devolve %s, esperado %s.',
                sys_get_temp_dir(),
                $temporario,
            ));
        }
    }
}
