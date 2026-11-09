<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * A infraestrutura do BlueJus Voto (spec docs/specs/bluejus-voto.md, D1-B, D2 e fase A2) fica no repositório SEM ligar
 * nada sozinha na produção: subir o container, o vhost e o certificado é passo manual do Samuel
 * (docs/runbooks/voto-subir-na-vps.md).
 *
 * Trava aqui:
 *  - o serviço `voto` do docker-compose.prod.yml só existe com o profile `voto`, sem porta publicada, com o papel do
 *    container e o banco do usuário próprio do Voto; nenhum outro serviço ganha profile (sairia do deploy);
 *  - o DATABASE_URL do `voto` não usa `:?`: o compose interpola até serviço de profile desligado, e a falta da
 *    variável derrubaria todo deploy do BlueJus;
 *  - o `voto` não recebe o .env.prod inteiro (sem env_file): nada de senha do banco do dono, APP_SECRET do BlueJus
 *    ou chave de R2, Anthropic, Google e Drive; e toda `%env(X)%` sem padrão da config está na lista dele, para quem
 *    acrescentar uma variável obrigatória saber antes de o container do Voto parar de subir;
 *  - nenhum script nem workflow liga o profile por conta própria;
 *  - o vhost só existe como `.https-pendente` (certificado ausente derruba o nginx compartilhado) e nada em
 *    nginx/conf.d atende o endereço do Voto.
 */
#[CoversNothing]
final class VotoInfraArquiteturaTest extends TestCase
{
    private const ENDERECO = 'voto.bluejus.com.br';
    private const VHOST_PENDENTE = 'scripts/vps/vhosts/voto.conf.https-pendente';
    /** Partes de nome ou de valor que nunca chegam ao container do Voto. */
    private const PROIBIDAS_NO_VOTO = ['POSTGRES_', 'R2_', 'ANTHROPIC', 'GOOGLE', 'SYNC_CRYPTO', '${APP_SECRET'];

    #[TestDox('o serviço voto só existe com o profile voto, sem ports, com APP_PAPEL voto e o banco vindo de VOTO_DATABASE_URL')]
    public function testServicoVotoSoComProfile(): void
    {
        $voto = $this->servicos()['voto'] ?? null;

        self::assertIsArray($voto, 'docker-compose.prod.yml sem o serviço voto');
        self::assertSame(['voto'], $voto['profiles'] ?? null, 'o serviço voto só sobe com o profile voto');
        self::assertArrayNotHasKey('ports', $voto, 'o voto não publica porta: só o nginx fala com ele');
        self::assertSame('voto', $voto['environment']['APP_PAPEL'] ?? null);
        self::assertSame('${VOTO_DATABASE_URL:-}', $voto['environment']['DATABASE_URL'] ?? null, 'banco do usuário próprio do Voto, sem `:?`');
        self::assertSame('${VOTO_APP_SECRET:-}', $voto['environment']['APP_SECRET'] ?? null, 'segredo próprio do Voto, nunca o do BlueJus');
        self::assertSame('jusprime_voto_prod', $voto['container_name'] ?? null);
        self::assertSame(['sh', '/var/www/app/bin/entrypoint.voto.sh'], $voto['entrypoint'] ?? null, 'entrypoint próprio: sem migrations e sem copiar assets');
        self::assertContains('./docker/voto/php-fpm-voto.conf:/usr/local/etc/php-fpm.d/zz-voto.conf:ro', $voto['volumes'] ?? []);
    }

    #[TestDox('o serviço voto não recebe o .env.prod inteiro nem segredo do BlueJus (banco do dono, APP_SECRET, R2, Anthropic, Google, Drive)')]
    public function testVotoSemSegredoDoBluejus(): void
    {
        $voto = $this->servicos()['voto'] ?? [];
        self::assertArrayNotHasKey('env_file', $voto, 'o .env.prod inteiro levaria ao Voto os segredos do BlueJus');

        $vazam = [];
        foreach ($this->ambienteDoVoto() as $nome => $valor) {
            foreach (self::PROIBIDAS_NO_VOTO as $proibida) {
                if (str_contains(strtoupper($nome), $proibida) || str_contains((string) $valor, $proibida)) {
                    $vazam[] = $nome . ' (' . $proibida . ')';
                }
            }
        }

        self::assertSame([], $vazam);
    }

    #[TestDox('toda variável de ambiente sem padrão da config está no environment do serviço voto')]
    public function testVariavelObrigatoriaDaConfigChegaAoVoto(): void
    {
        $faltando = array_values(array_diff($this->semPadraoNaConfig(), array_keys($this->ambienteDoVoto())));

        self::assertSame([], $faltando, 'Sem ela o container do Voto não sobe: acrescente ao environment do serviço voto no docker-compose.prod.yml (valor inofensivo se o Voto não a usa).');
    }

    #[TestDox('nenhum outro serviço do docker-compose.prod.yml tem profile (sairia do deploy sem aviso)')]
    public function testNenhumOutroServicoTemProfile(): void
    {
        $comProfile = [];
        foreach ($this->servicos() as $nome => $servico) {
            if ($nome !== 'voto' && \is_array($servico) && \array_key_exists('profiles', $servico)) {
                $comProfile[] = $nome;
            }
        }

        self::assertSame([], $comProfile);
    }

    #[TestDox('nenhum script (scripts/**/*.sh) nem workflow (.github/workflows/*.yml) liga um profile (COMPOSE_PROFILES ou --profile)')]
    public function testDeployNaoLigaProfile(): void
    {
        $arquivos = (new Finder())->files()->in($this->raiz() . '/scripts')->name('*.sh')
            ->append((new Finder())->files()->in($this->raiz() . '/.github/workflows')->name('*.yml'));
        $ligam = [];
        foreach ($arquivos as $arquivo) {
            $conteudo = $arquivo->getContents();
            if (str_contains($conteudo, 'COMPOSE_PROFILES') || str_contains($conteudo, '--profile')) {
                $ligam[] = $arquivo->getRelativePathname();
            }
        }
        self::assertGreaterThan(5, \count($arquivos), 'a varredura precisa achar os scripts e os workflows');

        self::assertSame([], $ligam, 'Ligar o Voto é passo manual do Samuel (docs/runbooks/voto-subir-na-vps.md).');
    }

    #[TestDox('nenhum arquivo em nginx/conf.d atende voto.bluejus.com.br')]
    public function testNadaNoConfDAtendeOVoto(): void
    {
        $mencionam = [];
        foreach ((new Finder())->files()->ignoreDotFiles(false)->in($this->raiz() . '/nginx/conf.d') as $arquivo) {
            if (str_contains($arquivo->getContents(), self::ENDERECO)) {
                $mencionam[] = $arquivo->getRelativePathname();
            }
        }

        self::assertSame([], $mencionam, 'O vhost do Voto entra no nginx só pela mão do Samuel, depois do certificado.');
    }

    #[TestDox('o vhost do Voto existe só como .https-pendente, com camera=(self), limit_req e access_log off na urna e no autorizar')]
    public function testVhostPendente(): void
    {
        self::assertFileDoesNotExist($this->raiz() . '/scripts/vps/vhosts/voto.conf', 'sem o sufixo, alguém o copia ativo antes do certificado');
        $vhost = $this->ler(self::VHOST_PENDENTE);

        self::assertStringContainsString('server_name ' . self::ENDERECO . ';', $vhost);
        self::assertStringContainsString('camera=(self)', $vhost);
        self::assertStringNotContainsString('camera=()', $vhost);
        self::assertMatchesRegularExpression('/^\s*limit_req\s+zone=voto_req\b/m', $vhost);
        self::assertMatchesRegularExpression('/^\s*limit_conn\s+voto_conn\b/m', $vhost);
        foreach (['/urna', '/autorizar'] as $prefixo) {
            self::assertMatchesRegularExpression(
                '#location \^~ ' . preg_quote($prefixo, '#') . ' \{\s*access_log off;[^}]*fastcgi_pass[^}]*\}#',
                $vhost,
                $prefixo . ' sem access_log e chamando o PHP ali mesmo (um try_files levaria o log de volta)',
            );
        }
    }

    /** @return array<string, mixed> environment do serviço voto, como está no arquivo (antes da interpolação) */
    private function ambienteDoVoto(): array
    {
        $ambiente = $this->servicos()['voto']['environment'] ?? null;
        self::assertIsArray($ambiente, 'o environment do voto é um mapa');

        return $ambiente;
    }

    /**
     * Variáveis `%env(X)%` sem `default:` nos YAML de config/, fora de when@dev e when@test: a mesma regra do
     * EnvDoBuildArquiteturaTest (lá, para o .env do build).
     *
     * @return list<string>
     */
    private function semPadraoNaConfig(): array
    {
        $achadas = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 2) . '/config')->name('*.yaml') as $arquivo) {
            $dados = Yaml::parseFile($arquivo->getPathname(), Yaml::PARSE_CUSTOM_TAGS);
            if (\is_array($dados)) {
                unset($dados['when@dev'], $dados['when@test']);
            }
            preg_match_all('/%env\(([^)%]+)\)%/', serialize($dados), $m);
            foreach ($m[1] as $expressao) {
                $partes = explode(':', $expressao);
                if (!\in_array('default', $partes, true)) {
                    $achadas[] = (string) end($partes);
                }
            }
        }
        $achadas = array_values(array_unique($achadas));
        sort($achadas);
        self::assertNotSame([], $achadas, 'a varredura da config precisa achar ao menos o APP_SECRET');

        return $achadas;
    }

    /** @return array<string, mixed> */
    private function servicos(): array
    {
        $compose = Yaml::parse($this->ler('docker-compose.prod.yml'));
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null);

        return $compose['services'];
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
