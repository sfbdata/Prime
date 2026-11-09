<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\EquipeDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\JustificativaPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDeJornadaPorEscritorioTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/**
 * O subtítulo da justificativa sai de um macro só (`ponto/_justificativa_macros.html.twig`, spec
 * `docs/specs/ponto-desenho-0811.md`, E5), usado por "Minhas Justificativas" (E3) e por "Justificativas a analisar"
 * (E5). O macro devolve o texto já escapado e sem espaço nas pontas; quem usa imprime como veio. Juntar com `~` vira
 * texto comum e escapa de novo (era o defeito do `data-info`); o `|trim` do Twig 3 mantém o Markup, mas deixou de ser
 * preciso.
 *
 * O macro de verdade só escreve nomes fixos e horas (`H:i`): nenhum dado chega a ter `&`. Para provar o escape, o
 * segundo teste troca o macro, só neste teste, por um que devolve `Entrada & saída <b>"x"</b>`, e confere as duas abas
 * de verdade.
 */
#[CoversClass(EquipeDoPontoController::class)]
#[CoversClass(PontoController::class)]
#[Group('ponto')]
final class EquipeDoPontoSubtituloTest extends JusPrimeWebTestCase
{
    use CenarioDeJornadaPorEscritorioTrait;

    private const TEXTO_COM_ESCAPE = 'Entrada & saída <b>"x"</b>';
    private const ESCAPADO_UMA_VEZ = 'Entrada &amp; saída &lt;b&gt;&quot;x&quot;&lt;/b&gt;';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('O macro de verdade não emite espaço nas pontas e, sem subtítulo, devolve vazio')]
    public function testMacroSemEspacoNasPontas(): void
    {
        $base = ['tipo' => 'atestado_medico', 'tipoRegistroEsquecido' => null, 'horaRegistroEsquecido' => null, 'abonoParcial' => false, 'horaInicioAbono' => null, 'horaFimAbono' => null];
        $esquecimento = ['tipo' => 'esquecimento_registro', 'tipoRegistroEsquecido' => 'saida', 'horaRegistroEsquecido' => new \DateTimeImmutable('2026-11-09 18:30')] + $base;
        $parcial = ['abonoParcial' => true, 'horaInicioAbono' => new \DateTimeImmutable('2026-11-09 08:00'), 'horaFimAbono' => new \DateTimeImmutable('2026-11-09 10:00')] + $base;

        $saida = $this->twig()->createTemplate(
            "{% import 'ponto/_justificativa_macros.html.twig' as jm %}"
            . '[{{ jm.subtitulo(a) }}][{{ jm.subtitulo(b) }}][{{ jm.subtitulo(c) }}]{{ jm.subtitulo(c) is same as(\'\') ? \'vazio\' }}',
        )->render(['a' => $esquecimento, 'b' => $parcial, 'c' => $base]);

        self::assertSame('[Saída às 18:30][Parcial: 08:00 às 10:00][]vazio', $saida);
    }

    #[TestDox('Subtítulo com & e <: sai escapado uma vez só em "Minhas Justificativas" (célula e data-info da lixeira e do lápis) e na lista da Equipe')]
    public function testEscapeUmaVezNasDuasAbas(): void
    {
        $escritorio = $this->escritorio('Escritório do subtítulo');
        $gestora = $this->pessoa('Gestora');
        $this->vincular($gestora, $escritorio, [], true);
        $ana = $this->pessoa('Ana Lima');
        $this->vincular($ana, $escritorio);
        $hoje = new \DateTimeImmutable('today');
        $this->justificativaPendente($ana, $escritorio, $hoje);
        $this->trocarOMacroPorUmComEscape();

        $this->logarComTenant($this->client, $ana, $escritorio);
        $minhas = $this->client->request('GET', '/ponto/?aba=justificativas');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $linha = $minhas->filter('#ponto-painel-justificativas tr.pe-just-lin');
        self::assertCount(1, $linha);
        self::assertSame(self::TEXTO_COM_ESCAPE, $linha->filter('td.pe-just-celtipo > div.pe-just-sub')->text());
        self::assertSame($hoje->format('d/m/Y') . ' · Atestado Médico · ' . self::TEXTO_COM_ESCAPE, $linha->filter('button[data-excluir-url]')->attr('data-info'));
        self::assertSame($hoje->format('d/m/Y') . ' · Atestado Médico · ' . self::TEXTO_COM_ESCAPE, $linha->filter('button[data-justificativa-editar]')->attr('data-info'));
        self::assertSame(3, substr_count($html, self::ESCAPADO_UMA_VEZ), 'na célula e no data-info da lixeira e do lápis (E4)');
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('&amp;lt;', $html);

        $this->logarComTenant($this->client, $gestora, $escritorio);
        $equipe = $this->client->request('GET', '/ponto/equipe');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(self::TEXTO_COM_ESCAPE, $equipe->filter('form.pe-eq-abonar > label.pe-eq-just > span.pe-eq-just-sub')->text());
        self::assertSame(1, substr_count($html, self::ESCAPADO_UMA_VEZ));
        self::assertStringNotContainsString('&amp;amp;', $html);
        self::assertStringNotContainsString('&amp;lt;', $html);
    }

    private function twig(): Environment
    {
        return static::getContainer()->get('twig');
    }

    /**
     * Só neste teste: o mesmo nome de template, servido antes do de verdade, com um subtítulo que precisa de escape. O
     * texto passa por uma variável porque o Twig não escapa uma constante escrita direto no `{{ }}`.
     */
    private function trocarOMacroPorUmComEscape(): void
    {
        $twig = $this->twig();
        $macro = "{%- macro subtitulo(j) -%}{%- set texto = '" . self::TEXTO_COM_ESCAPE . "' -%}{{- texto -}}{%- endmacro -%}";
        $twig->setLoader(new ChainLoader([new ArrayLoader(['ponto/_justificativa_macros.html.twig' => $macro]), $twig->getLoader()]));
    }

    private function justificativaPendente(User $pessoa, Tenant $escritorio, \DateTimeImmutable $dia): void
    {
        $j = new JustificativaPonto();
        $j->setUser($pessoa);
        $j->setTenant($escritorio);
        $j->setData(new \DateTime($dia->format('Y-m-d')));
        $j->setStatus('pendente');
        $j->setTipo('atestado_medico');
        $j->setBatchId(bin2hex(random_bytes(8)));
        $this->em()->persist($j);
        $this->em()->flush();
    }
}
