<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * O arranjo do validador de PDF da Assinatura nos dois compose (spec docs/specs/assinatura-documentos-etapa-6.md,
 * E6-1 a E6-3 e seção 6; etapa 8, E8-2). Ligar em produção é passo manual do Samuel
 * (docs/runbooks/assinatura-validador-em-producao.md); nenhum script liga o profile (VotoInfraArquiteturaTest).
 *
 * Trava aqui:
 *  - o `validador_pdf` só existe com o profile `assinatura`, construído de validador-pdf/ no alvo `producao`, só amd64;
 *  - redes: `assinatura_net` interna, só com php, worker e o validador; `validador_saida` só com o validador; o
 *    validador nunca na `saas_net` (lá roda o blog_builder com npm de terceiros) e sem porta publicada;
 *  - raiz só de leitura, /tmp em tmpfs, sem capabilities, e o healthcheck é o do Dockerfile;
 *  - em produção, teto de memória e CPU que cabe as conferências simultâneas, cada uma no RLIMIT do filho;
 *  - as âncoras no volume `validador_ancoras`, no caminho padrão do configuracao.py;
 *  - o ambiente do validador é só o que o código dele lê com padrão vazio (sem o .env.prod inteiro, e nenhuma
 *    variável que, vazia, derrubaria o contêiner ou apagaria a URL do ITI);
 *  - php e worker na `assinatura_net`, com a URL e o token, e ninguém com depends_on do validador (ele fica
 *    "unhealthy" enquanto as âncoras não chegam, e o php não pode deixar de subir por isso);
 *  - os secrets do selo só no validador e só no compose de produção.
 */
#[CoversNothing]
final class ValidadorPdfInfraArquiteturaTest extends TestCase
{
    private const SERVICO = 'validador_pdf';
    private const DEV = 'docker-compose.yml';
    private const PRODUCAO = 'docker-compose.prod.yml';
    private const CONFIGURACAO = 'validador-pdf/validador/configuracao.py';
    private const SECRETS_DO_SELO = ['selo_pkcs12', 'selo_pkcs12_senha'];

    /** @return iterable<string, array{string}> */
    public static function composes(): iterable
    {
        yield 'dev' => [self::DEV];
        yield 'produção' => [self::PRODUCAO];
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: o validador_pdf só existe com o profile assinatura, construído de validador-pdf no alvo producao, só amd64')]
    public function testServicoSoComProfile(string $arquivo): void
    {
        $validador = $this->validador($arquivo);

        self::assertSame(['assinatura'], $validador['profiles'] ?? null, 'sem o profile, o validador subiria em todo deploy');
        self::assertSame(['context' => './validador-pdf', 'target' => 'producao'], $validador['build'] ?? null);
        self::assertSame('linux/amd64', $validador['platform'] ?? null, 'os hashes dos requirements são só das wheels linux x86_64');
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: assinatura_net interna só com php, worker e o validador; validador_saida só com o validador; nunca na saas_net')]
    public function testRedes(string $arquivo): void
    {
        $compose = $this->compose($arquivo);
        $redes = $compose['networks'] ?? [];

        self::assertSame(['internal' => true], $redes['assinatura_net'] ?? null, 'a assinatura_net não tem saída para a internet');
        self::assertArrayHasKey('validador_saida', $redes);
        self::assertNotTrue(($redes['validador_saida'] ?? [])['internal'] ?? false, 'a validador_saida é a que baixa LCR, OCSP e cadeias do ITI');
        self::assertSame(['assinatura_net', 'validador_saida'], $this->redesDo($this->validador($arquivo)), 'o validador nunca entra na saas_net');

        self::assertSame(['php', 'validador_pdf', 'worker'], $this->quemEstaNa('assinatura_net', $compose));
        self::assertSame(['validador_pdf'], $this->quemEstaNa('validador_saida', $compose));
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: sem porta publicada, raiz só de leitura, /tmp em tmpfs, sem capabilities e com o healthcheck do Dockerfile')]
    public function testEndurecimento(string $arquivo): void
    {
        $validador = $this->validador($arquivo);

        self::assertArrayNotHasKey('ports', $validador, 'só o php e o worker falam com o validador, pela assinatura_net');
        self::assertArrayNotHasKey('network_mode', $validador);
        self::assertArrayNotHasKey('privileged', $validador);
        self::assertTrue($validador['read_only'] ?? false, 'raiz só de leitura (E6-1)');
        $tmpfs = array_filter((array) ($validador['tmpfs'] ?? []), static fn (mixed $m): bool => \is_string($m) && preg_match('#^/tmp(:|\z)#', $m) === 1);
        self::assertCount(1, $tmpfs, '/tmp em tmpfs: o forkserver do Python guarda o socket ali');
        self::assertSame(['ALL'], $validador['cap_drop'] ?? null);
        self::assertContains('no-new-privileges:true', (array) ($validador['security_opt'] ?? []));

        self::assertArrayNotHasKey('healthcheck', $validador, 'o healthcheck é o do Dockerfile (/saude)');
        $dockerfile = $this->ler('validador-pdf/Dockerfile');
        self::assertMatchesRegularExpression('#^HEALTHCHECK [^\n]*\\\\\n[^\n]*/saude#m', $dockerfile);
    }

    #[TestDox('produção: teto de 3584m de memória e 1,5 CPU, que cabe as conferências simultâneas no RLIMIT de cada filho')]
    public function testTetoDeRecursosEmProducao(): void
    {
        $validador = $this->validador(self::PRODUCAO);

        self::assertSame('3584m', $validador['mem_limit'] ?? null, 'teto do contêiner (o runbook, passo 0, diz quando reduzir)');
        self::assertSame(1.5, $validador['cpus'] ?? null);

        // O RLIMIT de cada filho é a primeira barreira; o teto do contêiner precisa caber os filhos e o principal.
        self::assertSame(1, preg_match('#^SIMULTANEAS = (\d+)$#m', $this->ler(self::CONFIGURACAO), $s), 'SIMULTANEAS não encontrado no configuracao.py');
        self::assertSame(1, preg_match("#'ASSINATURA_VALIDADOR_MEMORIA_MB', '(\\d+)'#", $this->ler('validador-pdf/validador/execucao.py'), $f), 'memória do filho não encontrada no execucao.py');
        self::assertGreaterThanOrEqual((int) $s[1] * (int) $f[1] + 512, 3584, 'filhos simultâneos no RLIMIT mais 512 MB do processo principal');
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: as âncoras ficam no volume validador_ancoras, no caminho padrão do configuracao.py')]
    public function testVolumeDasAncoras(string $arquivo): void
    {
        self::assertSame(1, preg_match("#^    diretorio_de_dados: str = '([^']+)'$#m", $this->ler(self::CONFIGURACAO), $m), 'caminho padrão das âncoras não encontrado no configuracao.py');
        $caminho = $m[1];

        self::assertSame(['validador_ancoras:' . $caminho], $this->validador($arquivo)['volumes'] ?? null);
        self::assertArrayHasKey('validador_ancoras', $this->compose($arquivo)['volumes'] ?? []);
        // Volume nomeado vazio herda o dono do ponto de montagem da imagem: o usuário 10001 grava as âncoras.
        self::assertStringContainsString('chown 10001:10001 ' . $caminho, $this->ler('validador-pdf/Dockerfile'));
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: o validador recebe só as variáveis que o código lê com padrão vazio, sem :? e sem o .env.prod inteiro')]
    public function testAmbienteDoValidador(string $arquivo): void
    {
        $validador = $this->validador($arquivo);
        self::assertArrayNotHasKey('env_file', $validador, 'o .env.prod inteiro levaria ao validador as senhas e chaves do BlueJus');
        $ambiente = $validador['environment'] ?? null;
        self::assertIsArray($ambiente, 'o environment do validador é um mapa');

        ['vazias' => $vazias, 'com_padrao' => $comPadrao] = $this->variaveisLidasPeloValidador();
        self::assertContains('ASSINATURA_VALIDADOR_TOKEN', $vazias);

        $nomes = array_keys($ambiente);
        sort($nomes);
        self::assertSame([], array_values(array_intersect($nomes, $comPadrao)), 'Vazia, esta variável derruba o contêiner (int(\'\')) ou apaga o padrão do código (URL do ITI, caminho do volume ou dos secrets).');
        self::assertSame($vazias, $nomes, 'Toda variável que o validador lê com padrão vazio vai no environment dele, e só elas.');
        foreach ($ambiente as $nome => $valor) {
            self::assertSame('${' . $nome . ':-}', $valor, $nome . ' sem `:?`: o compose interpola até serviço de profile desligado.');
        }
    }

    #[DataProvider('composes')]
    #[TestDox('$_dataName: php e worker na saas_net e na assinatura_net, e nenhum serviço com depends_on do validador')]
    public function testPhpEWorkerNaAssinaturaNetSemDependerDoValidador(string $arquivo): void
    {
        $servicos = $this->compose($arquivo)['services'];
        foreach (['php', 'worker'] as $nome) {
            $redes = $this->redesDo($servicos[$nome]);
            self::assertContains('saas_net', $redes, $nome);
            self::assertContains('assinatura_net', $redes, $nome);
            self::assertArrayHasKey('ASSINATURA_VALIDADOR_URL', $servicos[$nome]['environment'] ?? [], $nome);
            self::assertArrayHasKey('ASSINATURA_VALIDADOR_TOKEN', $servicos[$nome]['environment'] ?? [], $nome);
        }

        $dependem = [];
        foreach ($servicos as $nome => $servico) {
            $dependencias = (array) ($servico['depends_on'] ?? []);
            if (\in_array(self::SERVICO, array_is_list($dependencias) ? $dependencias : array_keys($dependencias), true)) {
                $dependem[] = $nome;
            }
        }
        self::assertSame([], $dependem, 'Sem depends_on do validador: com o ITI fora do ar ele fica "unhealthy" e o php não subiria (seção 6 da spec).');
    }

    #[TestDox('produção: php e worker leem a URL e o token do .env.prod, sem :?')]
    public function testAmbienteDoPhpEWorkerEmProducao(): void
    {
        $servicos = $this->compose(self::PRODUCAO)['services'];
        foreach (['php', 'worker'] as $nome) {
            foreach (['ASSINATURA_VALIDADOR_URL', 'ASSINATURA_VALIDADOR_TOKEN'] as $variavel) {
                self::assertSame('${' . $variavel . ':-}', $servicos[$nome]['environment'][$variavel] ?? null, $nome . ': ' . $variavel);
            }
        }
    }

    #[TestDox('dev: php e worker só repassam a URL e o token do shell (sem valor), sem passar por cima do .env.local nem da suíte')]
    public function testAmbienteDoPhpEWorkerEmDev(): void
    {
        $servicos = $this->compose(self::DEV)['services'];
        foreach (['php', 'worker'] as $nome) {
            foreach (['ASSINATURA_VALIDADOR_URL', 'ASSINATURA_VALIDADOR_TOKEN'] as $variavel) {
                $ambiente = $servicos[$nome]['environment'] ?? [];
                self::assertArrayHasKey($variavel, $ambiente, $nome);
                self::assertNull($ambiente[$variavel], $nome . ': ' . $variavel . ' com valor (até vazio) passa por cima do app/.env.local e chega à suíte PHP deste contêiner');
            }
        }
    }

    #[TestDox('produção: os secrets do selo vêm de /opt/jusprime/secrets e montam só no validador_pdf, no caminho que o configuracao.py lê')]
    public function testSecretsDoSeloSoNoValidadorEmProducao(): void
    {
        $compose = $this->compose(self::PRODUCAO);
        $configuracao = $this->ler(self::CONFIGURACAO);
        foreach (self::SECRETS_DO_SELO as $secret) {
            self::assertSame(['file' => '/opt/jusprime/secrets/' . $secret], $compose['secrets'][$secret] ?? null, $secret);
            self::assertStringContainsString("'/run/secrets/" . $secret . "'", $configuracao, 'o compose monta o secret em /run/secrets/<nome>');
        }
        self::assertSame(self::SECRETS_DO_SELO, $this->validador(self::PRODUCAO)['secrets'] ?? null);

        $usam = [];
        foreach ($compose['services'] as $nome => $servico) {
            foreach ((array) ($servico['secrets'] ?? []) as $secret) {
                $fonte = \is_array($secret) ? ($secret['source'] ?? null) : $secret;
                if ($nome !== self::SERVICO && \in_array($fonte, self::SECRETS_DO_SELO, true)) {
                    $usam[] = $nome;
                }
            }
        }
        self::assertSame([], $usam, 'O PHP nunca vê a chave do selo (E8-2).');
    }

    #[TestDox('dev: nenhum secret do selo (a chave do e-CNPJ só existe na VPS)')]
    public function testDevSemSecretsDoSelo(): void
    {
        $compose = $this->compose(self::DEV);

        self::assertArrayNotHasKey('secrets', $this->validador(self::DEV));
        self::assertSame([], array_values(array_intersect(self::SECRETS_DO_SELO, array_keys($compose['secrets'] ?? []))));
    }

    #[TestDox('o CI constrói o validador no alvo teste e roda os testes Python sem rede e com a raiz só de leitura')]
    public function testCiRodaOsTestesPython(): void
    {
        $workflow = $this->ler('.github/workflows/validador-pdf.yml');

        self::assertStringContainsString('docker build --target teste', $workflow);
        self::assertMatchesRegularExpression('#docker run --rm --network none --read-only --tmpfs /tmp\b#', $workflow);
        self::assertStringContainsString('docker build --target producao', $workflow);
    }

    /**
     * As variáveis de ambiente que o código do validador lê, separadas pelo padrão: vazio (vazia = "não configurado")
     * ou não vazio (porta, caminho, memória, URL: vazia, quebra).
     *
     * @return array{vazias: list<string>, com_padrao: list<string>}
     */
    private function variaveisLidasPeloValidador(): array
    {
        $vazias = [];
        $comPadrao = [];
        foreach ((new Finder())->files()->in($this->raiz() . '/validador-pdf/validador')->name('*.py') as $arquivo) {
            preg_match_all("#(?:\\benv|os\\.environ)\\.get\\(\\s*'([A-Z0-9_]+)'\\s*,\\s*([^,)]+)#", $arquivo->getContents(), $m, \PREG_SET_ORDER);
            foreach ($m as [, $nome, $padrao]) {
                if (trim($padrao) === "''") {
                    $vazias[] = $nome;
                } else {
                    $comPadrao[] = $nome;
                }
            }
        }
        $vazias = array_values(array_unique($vazias));
        $comPadrao = array_values(array_unique($comPadrao));
        sort($vazias);
        sort($comPadrao);
        self::assertNotSame([], $comPadrao, 'a varredura precisa achar ao menos a porta e o caminho das âncoras');

        return ['vazias' => $vazias, 'com_padrao' => $comPadrao];
    }

    /**
     * @param array<string, mixed> $compose
     *
     * @return list<string>
     */
    private function quemEstaNa(string $rede, array $compose): array
    {
        $quem = [];
        foreach ($compose['services'] as $nome => $servico) {
            if (\in_array($rede, $this->redesDo($servico), true)) {
                $quem[] = (string) $nome;
            }
        }
        sort($quem);

        return $quem;
    }

    /**
     * @param array<string, mixed> $servico
     *
     * @return list<string>
     */
    private function redesDo(array $servico): array
    {
        $redes = (array) ($servico['networks'] ?? []);

        return array_values(array_map('strval', array_is_list($redes) ? $redes : array_keys($redes)));
    }

    /** @return array<string, mixed> */
    private function validador(string $arquivo): array
    {
        $validador = $this->compose($arquivo)['services'][self::SERVICO] ?? null;
        self::assertIsArray($validador, $arquivo . ' sem o serviço ' . self::SERVICO);

        return $validador;
    }

    /** @return array<string, mixed> */
    private function compose(string $arquivo): array
    {
        $compose = Yaml::parse($this->ler($arquivo));
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null);

        return $compose;
    }

    private function ler(string $relativo): string
    {
        $caminho = $this->raiz() . '/' . $relativo;
        self::assertFileExists($caminho);

        return (string) file_get_contents($caminho);
    }

    private function raiz(): string
    {
        return \dirname(__DIR__, 3);
    }
}
