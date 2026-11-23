<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * Central de envios do /master (seção Comercial e modo venda na seção Indicações): todo campo e botão tem `id` estável e
 * único na página, `name` e rótulo associado (M5.13, pedido do dono de 23/11); o botão bloqueado de nascença mostra o
 * motivo escrito ao lado, ligado a ele por aria-describedby (M5.14). As duas seções vêm na mesma página, como o painel
 * monta (painel.html.twig), por isso o prefixo de cada instância precisa separar os ids.
 */
#[CoversNothing]
final class CentralDeEnviosAcessivelTest extends KernelTestCase
{
    private const CONTROLES = '.mc input, .mc select, .mc textarea, .mc button';

    private Crawler $doc;

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '<div class="mp-secao-externa" data-sec-externa="com" hidden>{% include "master/_comercial.html.twig" %}</div>'
            . '<div class="mp-secao-externa" data-sec-externa="ind" hidden>{% include "master/_indicacoes.html.twig" %}</div>',
        )->render();
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    #[TestDox('Todo campo e botão da Central, nos dois modos, tem id, name e rótulo associado')]
    public function testTodoControleTemIdNameERotulo(): void
    {
        $controles = $this->doc->filter(self::CONTROLES);
        self::assertGreaterThan(80, $controles->count(), 'a Central tem dezenas de controles nos dois modos');

        $semId = $semName = $semRotulo = [];
        foreach ($controles as $no) {
            $id = $no->getAttribute('id');
            $quem = $no->nodeName . '.' . $no->getAttribute('class') . ' [' . trim(substr($no->textContent, 0, 30)) . ']';
            if ($id === '') {
                $semId[] = $quem;
            }
            if ($no->getAttribute('name') === '') {
                $semName[] = $quem . ' #' . $id;
            }
            if (!$this->temRotulo($no, $id)) {
                $semRotulo[] = $quem . ' #' . $id;
            }
        }

        self::assertSame([], $semId, 'controles sem id');
        self::assertSame([], $semName, 'controles sem name');
        self::assertSame([], $semRotulo, 'controles sem rótulo associado');
    }

    #[TestDox('Os ids são únicos na página, mesmo com a Central aparecendo duas vezes')]
    public function testIdsUnicosNaPagina(): void
    {
        $ids = array_map(static fn (\DOMElement $no): string => $no->getAttribute('id'), iterator_to_array($this->doc->filter('[id]'), false));
        $repetidos = array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1));

        self::assertSame([], $repetidos, 'ids repetidos: ' . implode(', ', $repetidos));
        // Os dois modos têm o próprio prefixo.
        self::assertCount(1, $this->doc->filter('#mc-cp-enviar'));
        self::assertCount(1, $this->doc->filter('#mc-venda-cp-enviar'));
        self::assertCount(1, $this->doc->filter('#mc-rl-busca-com'));
        self::assertCount(1, $this->doc->filter('#mc-rl-busca-ind'));
    }

    #[TestDox('O botão bloqueado de nascença mostra o motivo escrito ao lado, ligado por aria-describedby')]
    public function testBloqueadosMostramOMotivo(): void
    {
        $bloqueados = $this->doc->filter('#mc-dsc-limpar, [data-mc-cp-rastrear][disabled]');
        // Limpar lista existe sempre; a leitura vem desligada conforme o rastreio global. O Encaminhar já não é bloqueado (M5.17).
        self::assertGreaterThanOrEqual(1, $bloqueados->count());
        self::assertCount(0, $this->doc->filter('#mc-rl-ctx-encaminhar-com[disabled], #mc-rl-ctx-encaminhar-ind[disabled]'));

        foreach ($bloqueados as $botao) {
            $alvo = $botao->getAttribute('aria-describedby');
            self::assertNotSame('', $alvo, 'sem aria-describedby: #' . $botao->getAttribute('id'));
            $motivo = $this->doc->filter('#' . $alvo);
            self::assertCount(1, $motivo, 'o motivo #' . $alvo . ' não existe');
            self::assertNotSame('', trim($motivo->text('')), 'o motivo #' . $alvo . ' está vazio');
            self::assertNull($motivo->getNode(0)->attributes->getNamedItem('hidden'), 'o motivo #' . $alvo . ' está escondido');
        }
    }

    #[TestDox('As linhas de retorno de cada bloco existem na página e começam escondidas, sem texto')]
    public function testLinhasDeRetornoFixas(): void
    {
        foreach (['[data-mc-envio-retorno]', '[data-mc-cp-aviso]', '[data-mc-rl-msg]', '[data-mc-dt-msg]', '[data-mc-msg]', '[data-mc-dsc-aviso]', '[data-mc-tst-erro]', '[data-mc-kpis-msg]'] as $seletor) {
            $linhas = $this->doc->filter('.mc ' . $seletor);
            self::assertGreaterThanOrEqual(1, $linhas->count(), $seletor);
            foreach ($linhas as $linha) {
                self::assertContains($linha->getAttribute('role'), ['status', 'alert'], $seletor . ' sem role');
            }
        }
    }

    private function temRotulo(\DOMElement $no, string $id): bool
    {
        if (trim($no->getAttribute('aria-label')) !== '' || trim($no->getAttribute('aria-labelledby')) !== '') {
            return true;
        }
        if ($id !== '' && $this->doc->filter('label[for="' . $id . '"]')->count() > 0) {
            return true;
        }
        for ($pai = $no->parentNode; $pai instanceof \DOMElement; $pai = $pai->parentNode) {
            if ($pai->nodeName === 'label') {
                return true;
            }
        }

        // O texto do botão é o nome dele; campo de entrada precisa de um dos rótulos acima.
        return $no->nodeName === 'button' && trim($no->textContent) !== '';
    }
}
