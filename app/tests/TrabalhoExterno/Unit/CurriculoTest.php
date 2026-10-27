<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\SituacaoAcademica;
use App\TrabalhoExterno\Enum\StatusCurriculo;
use App\TrabalhoExterno\Enum\TipoArquivoDoCurriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Service\CalculadoraDeImportanciaDoCurriculo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\FiltroDeCurriculos;
use App\TrabalhoExterno\Service\PercentualDoCurriculo;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O currículo e quem pode o quê, como no desenho 06 (spec VS 18.10.26 §4.5). */
#[CoversClass(Curriculo::class)]
#[CoversClass(DadosDoCurriculo::class)]
#[CoversClass(PoliticaDoCurriculo::class)]
#[CoversClass(CalculadoraDeImportanciaDoCurriculo::class)]
#[CoversClass(PercentualDoCurriculo::class)]
#[CoversClass(FiltroDeCurriculos::class)]
final class CurriculoTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-10-19 10:00:00');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidos(): iterable
    {
        yield 'sem nome' => [['nome' => ' ', 'email' => 'x', 'lgpd' => false], 'Informe o nome completo.'];
        yield 'e-mail' => [['email' => 'ana@semponto'], 'Informe um e-mail válido.'];
        yield 'situação' => [['situacao' => ''], 'Informe sua situação: advogado(a), estagiário(a) com OAB, estudante ou bacharel(a).'];
        yield 'OAB do advogado' => [['oabNum' => ''], 'Informe o número de inscrição na OAB.'];
        yield 'instituição do estagiário' => [['situacao' => 'estOab', 'inst' => ''], 'Informe a instituição de ensino.'];
        yield 'instituição do estudante' => [['situacao' => 'est', 'inst' => ''], 'Informe a instituição de ensino.'];
        yield 'LGPD' => [['lgpd' => false], 'É preciso autorizar o uso dos dados (LGPD) para enviar o currículo.'];
        yield 'OAB com letras' => [['oabNum' => '12A45'], 'Informe o número da OAB só com números, até 7 dígitos.'];
        yield 'OAB longa' => [['oabNum' => '12345678'], 'Informe o número da OAB só com números, até 7 dígitos.'];
        yield 'UF' => [['uf' => 'XX'], 'Escolha a UF na lista.'];
        yield 'UF da OAB' => [['oabUf' => 'ZZ'], 'Escolha a UF da OAB na lista.'];
        yield 'LinkedIn em http' => [['linkedin' => 'http://linkedin.com/in/ana'], 'Informe o LinkedIn com o endereço https do perfil em linkedin.com.'];
        yield 'LinkedIn de outro domínio' => [['linkedin' => 'https://linkedin.com.golpe.test/in/ana'], 'Informe o LinkedIn com o endereço https do perfil em linkedin.com.'];
        yield 'Lattes de outro domínio' => [['lattes' => 'https://lattes.exemplo.test/1'], 'Informe o Lattes com o endereço https em lattes.cnpq.br.'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('invalidos')]
    #[TestDox('validação na ordem e com o texto do desenho: $mensagem')]
    public function testValidacao(array $troca, string $mensagem): void
    {
        $this->expectException(DadosInvalidos::class);
        $this->expectExceptionMessage($mensagem);

        DadosDoCurriculo::deArray($troca + self::valido())->exigirValido();
    }

    #[TestDox('o formulário completo passa; `sit` vale como sinônimo de `situacao`; o Instagram perde o @ e o endereço')]
    public function testValidoENormalizado(): void
    {
        $d = DadosDoCurriculo::deArray(['situacao' => null, 'sit' => 'adv', 'insta' => '@https://instagram.com/ana.souza/', 'linkedin' => 'https://www.linkedin.com/in/ana'] + self::valido());
        $d->exigirValido();

        self::assertSame(SituacaoAcademica::Adv, $d->situacao);
        self::assertSame('https://www.linkedin.com/in/ana', $d->linkedin);
        self::assertSame('ana.souza', DadosDoCurriculo::deArray(['insta' => '@ana.souza'])->insta);
    }

    #[TestDox('o que o formulário esconde pela situação não fica gravado; experiências vazias e áreas fora da lista saem')]
    public function testCamposCondicionais(): void
    {
        $d = DadosDoCurriculo::deArray(['situacao' => 'est', 'oabNum' => '123', 'inst' => 'UnB', 'areas' => ['Cível', 'Inventada'], 'exps' => [['cargo' => '', 'org' => ''], ['cargo' => 'Estagiário', 'org' => 'TJDFT']]] + self::valido());

        self::assertSame('', $d->oabNum);
        self::assertSame('UnB', $d->inst);
        self::assertSame(['Cível'], $d->areas);
        self::assertCount(1, $d->exps);
        self::assertSame('', DadosDoCurriculo::deArray(['situacao' => 'adv', 'inst' => 'UnB'] + self::valido())->inst);
    }

    #[TestDox('criar: "Novo", recebido e atualizado agora; editar só muda o atualizado; status e nota ficam')]
    public function testCriarEEditar(): void
    {
        $cv = $this->curriculo();
        self::assertSame(StatusCurriculo::Novo, $cv->getStatus());
        self::assertEquals($this->agora, $cv->getRecebidoEm());
        self::assertSame(Curriculo::VERSAO_DO_CONSENTIMENTO, $cv->getVersaoDoConsentimento());

        $cv->mudarStatus(StatusCurriculo::Favorito);
        $cv->anotar('  Boa entrevista  ');
        $depois = $this->agora->modify('+1 day');
        $cv->editar(DadosDoCurriculo::deArray(['nome' => 'Ana Souza Lima'] + self::valido()), $depois);

        self::assertSame('Ana Souza Lima', $cv->texto('nome'));
        self::assertEquals($this->agora, $cv->getRecebidoEm(), 'o primeiro envio é imutável');
        self::assertEquals($depois, $cv->getAtualizadoEm());
        self::assertSame(StatusCurriculo::Favorito, $cv->getStatus());
        self::assertSame('Boa entrevista', $cv->getNota());
    }

    #[TestDox('consentimento: gravado no primeiro aceite; editar na mesma versão do texto não o regrava, versão nova regrava')]
    public function testConsentimento(): void
    {
        $cv = $this->curriculo();
        $cv->editar($this->dados(), $this->agora->modify('+1 day'));
        self::assertEquals($this->agora, $cv->getConsentidoEm());

        (new \ReflectionProperty($cv, 'versaoDoConsentimento'))->setValue($cv, '0');
        $cv->editar($this->dados(), $depois = $this->agora->modify('+2 days'));
        self::assertEquals($depois, $cv->getConsentidoEm());
        self::assertSame(Curriculo::VERSAO_DO_CONSENTIMENTO, $cv->getVersaoDoConsentimento());
    }

    #[TestDox('editar descarta o anexo do bloco que a nova situação esconde e devolve a chave para apagar depois do COMMIT')]
    public function testEditarDescartaAnexoDoBlocoEscondido(): void
    {
        $cv = $this->curriculo();
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Oab, 'oab.pdf', 'oab.pdf', $this->agora);
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Matricula, 'mat.pdf', 'mat.pdf', $this->agora);
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Foto, 'f.jpg', 'f.jpg', $this->agora);

        self::assertSame(['mat.pdf'], $cv->editar($this->dados(['situacao' => 'adv']), $this->agora), 'adv não tem bloco do curso');
        self::assertSame(['oab.pdf'], $cv->editar($this->dados(['situacao' => 'est', 'inst' => 'UnB']), $this->agora), 'estudante não tem bloco da OAB');
        self::assertTrue($cv->temArquivo(TipoArquivoDoCurriculo::Foto));
        self::assertSame([], $cv->editar($this->dados(['situacao' => 'est', 'inst' => 'UnB']), $this->agora));
    }

    #[TestDox('anexar devolve a chave substituída e remover devolve a removida, para apagar depois do COMMIT')]
    public function testArquivos(): void
    {
        $cv = $this->curriculo();
        self::assertNull($cv->anexarArquivo(TipoArquivoDoCurriculo::Foto, 'a.jpg', 'foto.jpg', $this->agora));
        self::assertSame('a.jpg', $cv->anexarArquivo(TipoArquivoDoCurriculo::Foto, 'b.jpg', 'foto.jpg', $this->agora));
        $cv->anexarArquivo(TipoArquivoDoCurriculo::Curriculo, 'c.pdf', 'cv.pdf', $this->agora);

        self::assertSame(['b.jpg', 'c.pdf'], $cv->chavesDosArquivos());
        self::assertSame('b.jpg', $cv->removerArquivo(TipoArquivoDoCurriculo::Foto, $this->agora));
        self::assertNull($cv->removerArquivo(TipoArquivoDoCurriculo::Foto, $this->agora));
        self::assertFalse($cv->temArquivo(TipoArquivoDoCurriculo::Foto));
    }

    #[TestDox('código CV-0001 de ida e volta; código de outro tipo não vira id')]
    public function testCodigo(): void
    {
        self::assertSame(12, Curriculo::idDoCodigo('CV-0012'));
        self::assertNull(Curriculo::idDoCodigo('VG-0012'));
        self::assertNull(Curriculo::idDoCodigo('CV-12; DROP'));
    }

    #[TestDox('política: o externo só vê e escreve o dele; o interno vê todos e não tem "Meu currículo"; gerir é talentos; excluir de outro é só do Master; a prévia não escreve')]
    public function testPolitica(): void
    {
        $politica = new PoliticaDoCurriculo(new PoliticaDaVaga(new PoliticaDaDemanda()), new PoliticaDaDemanda());
        $dono = Apoio::usuario(5);
        $cv = Curriculo::criar($dono, $this->dados(), $this->agora);
        $externo = Apoio::contexto(Perfil::Solicitante, usuario: $dono);
        $outro = Apoio::contexto(Perfil::Solicitante, usuario: Apoio::usuario(6));
        $master = Apoio::contexto(Perfil::Master);
        $semTalentos = new ConfiguracaoCentral();
        $semTalentos->definirEquipe('talentos', false);
        $equipe = Apoio::contexto(Perfil::Equipe, cfg: $semTalentos);
        $previa = new Contexto($master->usuario, $master->tenant, Perfil::Solicitante, $master->configuracao, null, true);

        self::assertTrue($politica->podeVer($externo, $cv) && $politica->podeEditar($externo, $cv) && $politica->podeExcluir($externo, $cv));
        self::assertFalse($politica->podeVer($outro, $cv));
        self::assertTrue($politica->podeVer($equipe, $cv));
        self::assertFalse($politica->podeGerir($equipe), 'Equipe sem talentos não gere');
        self::assertTrue($politica->podeGerir(Apoio::contexto(Perfil::Equipe)), 'a Equipe nasce com talentos');
        self::assertFalse($politica->podeExcluir($equipe, $cv));
        self::assertTrue($politica->podeExcluir($master, $cv) && $politica->podeGerir($master));
        self::assertFalse($politica->temMeuCurriculo($master), 'interno não tem "Meu currículo"');
        self::assertFalse($politica->podeEscreverOMeu($previa));
        self::assertFalse($politica->veNota($externo));
    }

    #[TestDox('importância (impC): novo, com OAB, currículo anexado e recebido na semana somam; a escolha manual vence')]
    public function testImportancia(): void
    {
        $calc = new CalculadoraDeImportanciaDoCurriculo();
        $cv = $this->curriculo();
        self::assertSame(['nivel' => 2, 'motivos' => ['ainda não analisado', 'com OAB', 'recebido nesta semana']], $calc->calcular($cv, null, $this->agora));

        $cv->anexarArquivo(TipoArquivoDoCurriculo::Curriculo, 'c.pdf', 'cv.pdf', $this->agora);
        $cv->mudarStatus(StatusCurriculo::Favorito);
        self::assertSame(3, $calc->calcular($cv, null, $this->agora)['nivel'], 'favorito +2, OAB, arquivo e semana: 5');
        self::assertSame(['nivel' => 2, 'motivos' => ['favorito', 'com OAB', 'currículo anexado']], $calc->calcular($cv, null, $this->agora->modify('+8 days')), 'depois de uma semana');
        self::assertSame(['nivel' => 0, 'motivos' => ['você marcou como não importante']], $calc->calcular($cv, false, $this->agora));
    }

    #[TestDox('percentual de 11 itens, cores e o "Complete: …" na ordem do desenho')]
    public function testPercentual(): void
    {
        $calc = new PercentualDoCurriculo();
        $cv = Curriculo::criar(Apoio::usuario(5), DadosDoCurriculo::deArray(['tel' => '', 'cidade' => '', 'resumo' => '', 'areas' => []] + self::valido()), $this->agora);
        self::assertSame(['pct' => 36, 'cor' => '#c76a12', 'faltas' => ['carteira da OAB', 'telefone', 'cidade', 'resumo', 'arquivo do currículo', 'foto de perfil', 'áreas de atuação']], $calc->calcular($cv));

        $completo = $this->curriculo();
        foreach ([TipoArquivoDoCurriculo::Oab, TipoArquivoDoCurriculo::Curriculo, TipoArquivoDoCurriculo::Foto] as $tipo) {
            $completo->anexarArquivo($tipo, $tipo->value, $tipo->value, $this->agora);
        }
        self::assertSame(['pct' => 100, 'cor' => '#1e7b45', 'faltas' => []], $calc->calcular($completo));

        $estudante = Curriculo::criar(Apoio::usuario(7), DadosDoCurriculo::deArray(['situacao' => 'est', 'inst' => 'UnB'] + self::valido()), $this->agora);
        self::assertSame('comprovante do curso', $calc->calcular($estudante)['faltas'][0]);
        self::assertSame('#0a6fa0', $calc->calcular($estudante)['cor'], '8 de 11 = 73%');
    }

    #[TestDox('filtro do CSV: aba Ativos esconde arquivados, Lixeira é pessoal, busca por OAB e área')]
    public function testFiltro(): void
    {
        $filtro = new FiltroDeCurriculos(new CalculadoraDeImportanciaDoCurriculo());
        $a = $this->curriculo(1);
        $b = $this->curriculo(2, ['nome' => 'Bruno Lima', 'oabNum' => '999', 'areas' => ['Trabalhista']]);
        $b->mudarStatus(StatusCurriculo::Arquivado);
        $lixo = new MarcacaoDoUsuario(Apoio::usuario(99), TipoItemMarcado::Curriculo, 1);
        $lixo->aplicar(OperacaoDeMarcacao::Lixeira, $this->agora);

        self::assertSame([], $filtro->aplicar([$a, $b], [1 => $lixo], [], $this->agora));
        self::assertSame([$a], $filtro->aplicar([$a, $b], [1 => $lixo], ['filtro' => 'lix'], $this->agora));
        self::assertSame([$b], $filtro->aplicar([$a, $b], [], ['filtro' => 'arquivado', 'busca' => '999', 'area' => 'Trabalhista'], $this->agora));
        // Termo (spec do termo de voluntariado): o estado do termo mais recente; sem entrada é "sem".
        self::assertSame([], $filtro->aplicar([$a], [], ['termo' => 'aceito'], $this->agora));
        self::assertSame([$a], $filtro->aplicar([$a], [], ['termo' => 'sem'], $this->agora));
        self::assertSame([$a], $filtro->aplicar([$a, $b], [], ['termo' => 'aceito'], $this->agora, [], [1 => 'aceito', 2 => 'aceito']));
        self::assertSame([$a], $filtro->aplicar([$a], [], ['termo' => 'enviado'], $this->agora, [], [1 => 'enviado']));
        self::assertSame([], $filtro->aplicar([$a], [], ['termo' => 'sem'], $this->agora, [], [1 => 'enviado']));
        self::assertSame([$a], $filtro->aplicar([$a], [], ['termo' => ''], $this->agora, [], [1 => 'aceito']), 'sem filtro de termo, todos');
    }

    /** @param array<string, mixed> $troca */
    private function curriculo(int $id = 1, array $troca = []): Curriculo
    {
        $cv = Curriculo::criar(Apoio::usuario(100 + $id), $this->dados($troca), $this->agora);
        (new \ReflectionProperty($cv, 'id'))->setValue($cv, $id);

        return $cv;
    }

    /** @param array<string, mixed> $troca */
    private function dados(array $troca = []): DadosDoCurriculo
    {
        return DadosDoCurriculo::deArray($troca + self::valido());
    }

    /** @return array<string, mixed> */
    private static function valido(): array
    {
        return [
            'nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'resumo' => 'Atuação cível.', 'areas' => ['Cível'], 'lgpd' => true,
        ];
    }
}
