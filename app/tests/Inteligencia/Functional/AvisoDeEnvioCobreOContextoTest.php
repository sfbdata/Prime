<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Controller\ConfiguracaoController;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * IA-001b: o aviso de envio da tela de configuração promete "nunca descrever menos do que é
 * enviado". Este teste amarra a promessa ao CÓDIGO, não a uma lista escrita à mão: roda os
 * montadores de verdade (serviços do container, banco de teste) sobre uma pasta real com processo
 * e publicação, monta os prompts de verdade (com análise anterior) e confere que cada coisa que
 * sai tem menção no aviso:
 *
 *   · cada CHAVE do cabeçalho do {@see MontadorDeContextoDaPasta} ↔ `data-ia-envio="pasta.<chave>"`,
 *     nem mais nem menos — chave nova no cabeçalho derruba o teste;
 *   · cada CAMPO da linha do processo vinculado (segmentos separados por " · ") ↔ um
 *     `data-ia-envio="processo.*"` — campo novo na linha derruba o teste;
 *   · cada BLOCO delimitado (`<tag>`) do prompt dos sete agentes e do Push ↔ `data-ia-bloco` ou
 *     `data-ia-secao` — bloco novo derruba o teste;
 *   · cada chave do cabeçalho do {@see MontadorDeContextoDoPush} ↔ um item já listado (mapa
 *     explícito abaixo; chave sem mapa derruba o teste).
 */
#[CoversClass(ConfiguracaoController::class)]
final class AvisoDeEnvioCobreOContextoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    /**
     * O cabeçalho do Push é um subconjunto do que a pasta e os processos já declaram. Chave nova
     * no cabeçalho do Push sem entrada aqui faz o teste cair — e obriga a decidir onde o aviso a cita.
     */
    private const CABECALHO_DO_PUSH_NO_AVISO = [
        'pasta' => 'pasta.pasta',
        'processo' => 'processo.numero',
        'classe' => 'processo.classe',
        'assunto' => 'processo.assunto',
        'tribunal' => 'processo.tribunal',
        'orgao' => 'processo.orgao',
        'responsavel' => 'pasta.responsavel',
        'equipe' => 'pasta.equipe',
    ];

    #[TestDox('o aviso de envio cita cada campo e cada bloco que os montadores e os prompts realmente enviam')]
    public function testAvisoCobreTudoQueSai(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->criarAdmin();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/admin/inteligencia');
        self::assertResponseIsSuccessful();
        $aviso = $crawler->filter('#ia-configuracao #ia-aviso-envio');
        self::assertSame(1, $aviso->count());

        // Pasta real, com tudo preenchido: processo vinculado com todos os campos do cadastro e
        // uma publicação (o Push só monta cabeçalho e movimentações com processo vinculado).
        [$pasta, $processo] = $this->criarPastaComPublicacao($tenant);
        $pasta->setNomeAcao('Cobrança');
        $pasta->setResponsavel($admin);
        $pasta->setPrioridade(PrioridadePasta::Urgente);
        $processo->setAssuntoProcessual('Inadimplemento');
        $processo->setSiglaTribunal('TJDFT');
        $processo->setOrgaoJulgador('1ª Vara Cível de Brasília');
        $processo->setSituacaoProcesso('Em andamento');
        $this->em()->flush();

        $envio = $this->atributos($aviso, 'data-ia-envio');
        $blocosDoAviso = array_merge(
            ...array_map(static fn (string $v): array => preg_split('/\s+/', trim($v)) ?: [], $this->atributos($aviso, 'data-ia-bloco')),
        );
        $secoesDoAviso = $this->atributos($aviso, 'data-ia-secao');

        // ---- Agentes: cabeçalho, linha do processo e blocos do prompt --------------------------
        $montadorDaPasta = static::getContainer()->get(MontadorDeContextoDaPasta::class);
        $promptDoAgente = new PromptDoAgente();
        $tagsEnviadas = [];
        foreach (Agente::cases() as $agente) {
            $contexto = $montadorDaPasta->para($tenant, $pasta, $agente, true);

            $chavesDaPasta = array_map(static fn (string $c): string => 'pasta.' . $c, array_keys($contexto->cabecalho));
            $pastaNoAviso = array_values(array_filter($envio, static fn (string $v): bool => str_starts_with($v, 'pasta.')));
            sort($chavesDaPasta);
            sort($pastaNoAviso);
            self::assertSame($chavesDaPasta, $pastaNoAviso, sprintf('cabeçalho da pasta (%s) e aviso divergem', $agente->value));

            self::assertNotSame([], $contexto->processos, 'a pasta do teste tem processo vinculado');
            foreach ($contexto->processos as $linha) {
                $campos = explode(' · ', (string) preg_replace('/\s*\[nível \d+, [^\]]+\]$/u', '', $linha));
                $processoNoAviso = array_filter($envio, static fn (string $v): bool => str_starts_with($v, 'processo.'));
                self::assertCount(
                    \count($campos),
                    $processoNoAviso,
                    sprintf('a linha do processo envia %d campos (%s); o aviso cita %d', \count($campos), $linha, \count($processoNoAviso)),
                );
            }

            $pedido = $promptDoAgente->montar($contexto, 'Resumo da análise anterior.');
            $tagsEnviadas = array_merge($tagsEnviadas, $this->tagsDe($pedido->textoDoUsuario()));
        }

        // ---- Push: cabeçalho e blocos do prompt ----------------------------------------------
        $contextoDoPush = static::getContainer()->get(MontadorDeContextoDoPush::class)->para($tenant, $pasta);
        self::assertNotSame([], $contextoDoPush->itens, 'a pasta do teste tem publicação: o bloco de movimentações não sai vazio');
        foreach (array_keys($contextoDoPush->cabecalho) as $chave) {
            self::assertArrayHasKey($chave, self::CABECALHO_DO_PUSH_NO_AVISO, "chave '$chave' do cabeçalho do Push sem lugar no aviso");
            self::assertContains(self::CABECALHO_DO_PUSH_NO_AVISO[$chave], $envio, "chave '$chave' do Push não citada no aviso");
        }
        $pedidoDoPush = (new PromptResumoDoPush())->montar($contextoDoPush, 'Resumo da análise anterior.');
        $tagsEnviadas = array_merge($tagsEnviadas, $this->tagsDe($pedidoDoPush->textoDoUsuario()));

        // ---- Cada bloco que sai tem menção -------------------------------------------------
        $tagsEnviadas = array_values(array_unique($tagsEnviadas));
        foreach (['pasta', 'processos_vinculados', 'processo', 'equipe', 'movimentacoes', 'analise_anterior', 'financeiro'] as $esperada) {
            self::assertContains($esperada, $tagsEnviadas, "sanidade: o bloco <$esperada> deveria ter saído nos prompts do teste");
        }
        foreach ($tagsEnviadas as $tag) {
            self::assertTrue(
                \in_array($tag, $blocosDoAviso, true) || \in_array($tag, $secoesDoAviso, true),
                "o bloco <$tag> é enviado ao provedor e o aviso não o menciona",
            );
        }

        // Cada marcação do aviso tem texto visível (não é só atributo).
        $aviso->filter('[data-ia-envio], [data-ia-bloco]')->each(static function (Crawler $no): void {
            self::assertNotSame('', trim($no->text()), 'item do aviso sem texto');
        });
        self::assertStringContainsString('análise anterior', $aviso->text());
        self::assertStringNotContainsString('—', $aviso->text());
    }

    /** @return list<string> */
    private function atributos(Crawler $raiz, string $atributo): array
    {
        return array_values(array_map('strval', $raiz->filter('[' . $atributo . ']')->each(
            static fn (Crawler $no): string => (string) $no->attr($atributo),
        )));
    }

    /**
     * Tags de abertura que delimitam blocos (linha só com `<tag>`). O neutralizador garante que
     * nenhum dado abre uma tag dessas, então o que casa aqui é estrutura do prompt.
     *
     * @return list<string>
     */
    private function tagsDe(string $texto): array
    {
        preg_match_all('/^<([a-z_]+)>$/m', $texto, $m);

        return $m[1];
    }
}
