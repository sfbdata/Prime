<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras da P2 que o código tem de carregar, não só a spec (spec P2 §0, §4, §9).
 *
 *  - **Nenhuma exclusão remota** (regra 0): o contrato não tem o verbo, e nenhum arquivo de `src/`
 *    chama exclusão do S3.
 *  - **O SDK fica no adaptador**: só `src/Shared/EspelhoRemoto/R2/` conhece o `async-aws`.
 *  - **Ligado só pela configuração, e desligado por padrão (P2.3)**: nenhum arquivo de `src/` fora
 *    do diretório do espelho o usa; a única ligação é o `services.yaml` (o decorador por cima do
 *    disco) e o `doctrine.yaml` (a tabela da outbox). Credencial só como NOME de variável de
 *    ambiente, dentro de `%env(...)%`, nunca valor.
 */
#[CoversNothing]
final class EspelhoRemotoArquiteturaTest extends TestCase
{
    private const DIRETORIO_DO_ESPELHO = 'src/Shared/EspelhoRemoto/';
    private const DIRETORIO_DO_ADAPTADOR = 'src/Shared/EspelhoRemoto/R2/';

    #[TestDox('o contrato do espelho só tem enviar, consultar, existe e baixar — nenhum verbo de exclusão (regra 0 da P2)')]
    public function testContratoSemExclusao(): void
    {
        $metodos = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(EspelhoRemoto::class))->getMethods(),
        );
        sort($metodos);

        self::assertSame(['baixar', 'consultar', 'enviar', 'existe'], $metodos);
    }

    #[TestDox('nenhum arquivo de src/ chama exclusão, expiração, cópia ou retenção do S3')]
    public function testNinguemChamaOperacaoDestrutivaRemota(): void
    {
        self::assertSame([], $this->arquivosQueCasam('/\b(?:deleteObjects?|deleteBucket\w*|putBucketLifecycle\w*|abortMultipartUpload|copyObject|putObjectRetention|putObjectLegalHold|putBucket\w*)\s*\(|\bDeleteObjects?(?:Request|Input)\b/i'));
    }

    /** Lista PERMITIDA, não proibida: o adaptador só pode chamar estas três operações no cliente. */
    #[TestDox('o adaptador R2 só chama putObject, headObject e getObject no cliente S3')]
    public function testAdaptadorSoChamaPutEHead(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(\dirname(__DIR__, 2) . '/src/Shared/EspelhoRemoto/R2/EspelhoRemotoR2.php'));
        preg_match_all('/->cliente->(\w+)\s*\(/', $codigo, $chamadas);
        $operacoes = array_values(array_unique($chamadas[1]));
        sort($operacoes);

        self::assertSame(['getObject', 'headObject', 'putObject'], $operacoes);
        self::assertSame(0, preg_match('/\$cliente\b(?!->)|->cliente\b(?!->)/', str_replace(['private readonly S3Client $cliente', 'S3Client $cliente'], '', $codigo)), 'o cliente não pode ser repassado para fora');
    }

    /**
     * A única exceção é a retenção da outbox (decisão do dono de 23/10, I4): ela apaga LINHAS LOCAIS da
     * tabela `armazenamento_replicacao`, nunca objeto remoto. O arquivo entra só com aquele `DELETE`.
     */
    private const OUTBOX = 'src/Shared/EspelhoRemoto/Outbox/OutboxDeReplicacao.php';

    #[TestDox('dentro do espelho não existe a palavra delete fora de comentário (só o DELETE da retenção da outbox, que é linha local)')]
    public function testAdaptadorNaoConheceDelete(): void
    {
        $achados = array_filter(
            $this->arquivosQueCasam('/delete/i'),
            static fn (string $arquivo): bool => str_starts_with($arquivo, self::DIRETORIO_DO_ESPELHO) && $arquivo !== self::OUTBOX,
        );

        self::assertSame([], array_values($achados));

        $outbox = $this->semComentarios((string) file_get_contents(\dirname(__DIR__, 2) . '/' . self::OUTBOX));
        self::assertSame(1, preg_match_all('/delete/i', $outbox), 'a outbox só conhece um delete');
        self::assertStringContainsString("'DELETE FROM ' . self::TABELA . ' WHERE ' . self::CONCLUIDAS_ANTES_DE", $outbox);
    }

    #[TestDox('o async-aws só é conhecido pelo adaptador R2')]
    public function testSdkSoNoAdaptador(): void
    {
        $fora = array_filter(
            $this->arquivosQueCasam('/\bAsyncAws\\\\/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, self::DIRETORIO_DO_ADAPTADOR),
        );

        self::assertSame([], array_values($fora));
    }

    #[TestDox('o layout de chaves é puro: sem framework, sem SDK, sem ambiente, sem banco')]
    public function testLayoutEhPuro(): void
    {
        $codigo = $this->semComentarios((string) file_get_contents(\dirname(__DIR__, 2) . '/src/Shared/EspelhoRemoto/LayoutDeChaveRemota.php'));

        self::assertDoesNotMatchRegularExpression('/\b(?:Symfony|Doctrine|AsyncAws|getenv|\$_(?:ENV|SERVER)|EntityManager|Connection)\b/', $codigo);
    }

    #[TestDox('o espelho não tem consumidor em src/ fora do próprio diretório — a ligação é só de configuração')]
    public function testEspelhoNaoEstaLigado(): void
    {
        $fora = array_filter(
            $this->arquivosQueCasam('/\bApp\\\\Shared\\\\EspelhoRemoto\b|\bEspelhoRemoto(?:R2)?\b|\bClienteR2\b/'),
            static fn (string $arquivo): bool => !str_starts_with($arquivo, self::DIRETORIO_DO_ESPELHO)
                // A regra de Tarefa oferecida à reconciliação (Shared não importa domínio).
                && $arquivo !== 'src/Tarefa/Armazenamento/TradutorDeAnexoDeTarefaPelaFabrica.php',
        );

        self::assertSame([], array_values($fora), 'a ligação do espelho é só pela configuração (services.yaml)');
    }

    #[TestDox('configuração: o espelho só aparece no services.yaml e no doctrine.yaml, e nenhum valor de R2 fica no repositório')]
    public function testConfiguracaoSoNosLugaresCertos(): void
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/config', \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            $conteudo = (string) file_get_contents($arquivo->getPathname());
            if (preg_match('/EspelhoRemoto|ClienteR2|AsyncAws|ESPELHO_R2_|r2\.cloudflarestorage/i', $conteudo) === 1) {
                $achados[] = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            }
            self::assertDoesNotMatchRegularExpression('/r2\.cloudflarestorage\.com|bluejus-(?:arquivos|backup-uploads)/i', $conteudo, 'endpoint e bucket reais são de variável de ambiente');
        }
        sort($achados);

        self::assertSame(['config/packages/doctrine.yaml', 'config/services.yaml'], $achados);
    }

    #[TestDox('as variáveis ESPELHO_R2_* só aparecem como nome dentro de %env(...)%, com padrão vazio')]
    public function testCredencialSoComoVariavelDeAmbiente(): void
    {
        $servicos = (string) file_get_contents(\dirname(__DIR__, 2) . '/config/services.yaml');
        preg_match_all('/ESPELHO_R2_[A-Z_]+/', $servicos, $todas);
        preg_match_all('/%env\(string:default::(ESPELHO_R2_[A-Z_]+)\)%/', $servicos, $comoEnv);

        self::assertCount(4, $comoEnv[1], 'endpoint, bucket, chave e segredo');
        self::assertSame(\count($todas[0]), \count($comoEnv[1]), 'toda menção é leitura de variável de ambiente');
        self::assertMatchesRegularExpression('/%env\(string:default::ARMAZENAMENTO_ESPELHO_CATEGORIAS\)%/', $servicos, 'a política vem de variável vazia por padrão');
    }

    /** @return list<string> */
    private function arquivosQueCasam(string $padrao): array
    {
        $raiz    = \dirname(__DIR__, 2);
        $achados = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz . '/src', \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            /** @var \SplFileInfo $arquivo */
            if ($arquivo->getExtension() !== 'php') {
                continue;
            }

            if (preg_match($padrao, $this->semComentarios((string) file_get_contents($arquivo->getPathname()))) === 1) {
                $achados[] = substr($arquivo->getPathname(), \strlen($raiz) + 1);
            }
        }

        sort($achados);

        return $achados;
    }

    private function semComentarios(string $codigo): string
    {
        $limpo = '';
        foreach (token_get_all($codigo) as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $limpo .= \is_array($token) ? $token[1] : $token;
        }

        return $limpo;
    }
}
